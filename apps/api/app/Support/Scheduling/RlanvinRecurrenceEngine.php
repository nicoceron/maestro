<?php

namespace App\Support\Scheduling;

use App\Contracts\Scheduling\RecurrenceEngine;
use App\Enums\LocalTimeResolution;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RRule\RRule;
use Throwable;

final class RlanvinRecurrenceEngine implements RecurrenceEngine
{
    private const ALLOWED_PARTS = [
        'FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYDAY', 'BYMONTHDAY', 'BYMONTH', 'BYSETPOS', 'WKST',
    ];

    private const ORDER = [
        'FREQ', 'INTERVAL', 'COUNT', 'UNTIL', 'BYMONTH', 'BYMONTHDAY', 'BYDAY', 'BYSETPOS', 'WKST',
    ];

    public function canonicalize(?string $rule): ?string
    {
        if ($rule === null || trim($rule) === '') {
            return null;
        }

        $rule = strtoupper(trim(preg_replace('/^RRULE:/i', '', trim($rule)) ?? ''));
        $parts = [];

        foreach (explode(';', $rule) as $part) {
            if (! str_contains($part, '=')) {
                $this->invalid('Recurrence rules must use RFC 5545 KEY=VALUE parts.');
            }

            [$key, $value] = array_map('trim', explode('=', $part, 2));

            if ($key === '' || $value === '' || isset($parts[$key])) {
                $this->invalid('Recurrence rule parts must be non-empty and unique.');
            }

            if (! in_array($key, self::ALLOWED_PARTS, true)) {
                $this->invalid("The {$key} recurrence part is not supported.");
            }

            $parts[$key] = $value;
        }

        if (! in_array($parts['FREQ'] ?? null, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
            $this->invalid('FREQ must be DAILY, WEEKLY, MONTHLY, or YEARLY.');
        }

        if (isset($parts['COUNT'], $parts['UNTIL'])) {
            $this->invalid('COUNT and UNTIL cannot be combined.');
        }

        if (isset($parts['COUNT']) && (! ctype_digit($parts['COUNT']) || (int) $parts['COUNT'] < 1 || (int) $parts['COUNT'] > 5000)) {
            $this->invalid('COUNT must be between 1 and 5000.');
        }

        if (isset($parts['INTERVAL']) && (! ctype_digit($parts['INTERVAL']) || (int) $parts['INTERVAL'] < 1 || (int) $parts['INTERVAL'] > 366)) {
            $this->invalid('INTERVAL must be between 1 and 366.');
        }

        if (isset($parts['UNTIL']) && preg_match('/^\d{8}T\d{6}Z$/', $parts['UNTIL']) !== 1) {
            $this->invalid('UNTIL must be an RFC 5545 UTC date-time such as 20261231T235959Z.');
        }

        $canonical = [];

        foreach (self::ORDER as $key) {
            if (isset($parts[$key])) {
                $canonical[] = $key.'='.$parts[$key];
            }
        }

        try {
            new RRule(implode(';', $canonical), new DateTimeImmutable('2024-01-01 12:00:00', new DateTimeZone('UTC')));
        } catch (Throwable) {
            $this->invalid('The recurrence rule is invalid.');
        }

        return implode(';', $canonical);
    }

    public function expand(
        string $localStart,
        string $timezone,
        ?string $rule,
        CarbonImmutable $from,
        CarbonImmutable $through,
        int $limit = 2000,
        LocalTimeResolution $startResolution = LocalTimeResolution::Reject,
        array $rdates = [],
        array $exdates = [],
    ): array {
        if ($limit < 1 || $limit > 5000 || $through->lessThan($from)) {
            throw new InvalidArgumentException('The recurrence expansion bounds are invalid.');
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $this->invalid('The timezone must be an IANA timezone identifier.', 'timezone');
        }

        $resolvedStart = ZonedLocalDateTime::resolve($localStart, $timezone, $startResolution, 'dtstart_local');
        $local = $resolvedStart->setTimezone($timezone)->toDateTimeImmutable();

        if (count($rdates) > 200 || count($exdates) > 200) {
            $this->invalid('RDATE and EXDATE are limited to 200 values each.');
        }

        $canonical = $this->canonicalize($rule);
        try {
            $dates = $canonical === null
                ? [$local]
                : (new RRule($canonical, $local))->getOccurrencesBetween(
                    $from->setTimezone($timezone)->toDateTime(),
                    $through->setTimezone($timezone)->toDateTime(),
                    $limit + 1,
                );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->invalid('The recurrence rule could not be expanded.');
        }

        if (count($dates) > $limit) {
            $this->invalid("The recurrence produces more than {$limit} occurrences in this horizon.");
        }

        $seeds = [];
        $excluded = array_fill_keys(array_map('strval', $exdates), true);

        $requestedTime = substr($localStart, 11);

        foreach ($dates as $date) {
            $identity = $date->format('Y-m-d').'T'.$requestedTime;

            if (isset($excluded[$identity])) {
                continue;
            }

            try {
                $instant = ZonedLocalDateTime::resolve($identity, $timezone, $startResolution, 'rrule');
            } catch (ValidationException $exception) {
                $instant = ZonedLocalDateTime::resolve(
                    $identity,
                    $timezone,
                    LocalTimeResolution::NormalizeForward,
                    'rrule',
                    allowGeneratedGapNormalization: true,
                );
            }

            if ($instant->lessThan($from) || $instant->greaterThan($through)) {
                continue;
            }

            $seeds[$identity] = new OccurrenceSeed(
                recurrenceIdLocal: $identity,
                startsAt: $instant,
                utcOffsetSeconds: $instant->setTimezone($timezone)->getOffset(),
            );
        }

        foreach ($rdates as $index => $rdate) {
            if (! is_array($rdate) || ! is_string($rdate['local'] ?? null)) {
                $this->invalid('Each RDATE needs a local date-time and resolution.');
            }

            $identity = $rdate['local'];

            if (isset($excluded[$identity])) {
                continue;
            }

            $resolution = LocalTimeResolution::tryFrom((string) ($rdate['resolution'] ?? 'reject'))
                ?? LocalTimeResolution::Reject;
            $instant = ZonedLocalDateTime::resolve($identity, $timezone, $resolution, "rdates.{$index}.local");

            if ($instant->betweenIncluded($from, $through)) {
                $seeds[$identity] = new OccurrenceSeed(
                    recurrenceIdLocal: $identity,
                    startsAt: $instant,
                    utcOffsetSeconds: $instant->setTimezone($timezone)->getOffset(),
                );
            }
        }

        ksort($seeds);

        return array_values($seeds);
    }

    private function invalid(string $message, string $field = 'rrule'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
