<?php

namespace App\Support\Scheduling;

use App\Enums\LocalTimeResolution;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class ZonedLocalDateTime
{
    public static function resolve(
        string $value,
        string $timezone,
        LocalTimeResolution $resolution,
        string $field,
        bool $allowGeneratedGapNormalization = false,
    ): CarbonImmutable {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages(['timezone' => 'The timezone must be an IANA timezone identifier.']);
        }

        $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, new DateTimeZone('UTC'));

        if ($wall === false || $wall->format('Y-m-d\TH:i:s') !== $value) {
            throw ValidationException::withMessages([$field => 'The date and time must use the Y-m-dTH:i:s format.']);
        }

        $zone = new DateTimeZone($timezone);
        $matches = [];

        foreach ($zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800) as $transition) {
            $candidate = CarbonImmutable::createFromTimestampUTC($wall->getTimestamp() - (int) $transition['offset']);

            if ($candidate->setTimezone($zone)->format('Y-m-d\TH:i:s') === $value) {
                $matches[$candidate->getTimestamp()] = $candidate;
            }
        }

        ksort($matches);
        $matches = array_values($matches);

        if (count($matches) === 1) {
            return $matches[0];
        }

        if ($matches === []) {
            if ($resolution !== LocalTimeResolution::NormalizeForward || ! $allowGeneratedGapNormalization) {
                throw ValidationException::withMessages([
                    $field => 'This local time does not exist because of a daylight-saving transition.',
                ]);
            }

            $normalized = new DateTimeImmutable(str_replace('T', ' ', $value), $zone);

            return CarbonImmutable::instance($normalized)->utc();
        }

        return match ($resolution) {
            LocalTimeResolution::Earlier => $matches[0],
            LocalTimeResolution::Later => $matches[array_key_last($matches)],
            default => throw ValidationException::withMessages([
                $field => 'This local time occurs twice; choose earlier or later.',
            ]),
        };
    }
}
