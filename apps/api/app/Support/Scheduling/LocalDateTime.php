<?php

namespace App\Support\Scheduling;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class LocalDateTime
{
    public static function toUtc(string $value, string $timezone, string $field): CarbonImmutable
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::withMessages([
                'timezone' => 'The timezone must be an IANA timezone identifier.',
            ]);
        }

        $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, new DateTimeZone('UTC'));

        if ($wall === false || $wall->format('Y-m-d\TH:i:s') !== $value) {
            throw ValidationException::withMessages([
                $field => 'The date and time must use the Y-m-dTH:i:s format.',
            ]);
        }

        $zone = new DateTimeZone($timezone);
        $matches = [];

        foreach ($zone->getTransitions($wall->getTimestamp() - 86400, $wall->getTimestamp() + 86400) as $transition) {
            $offset = (int) $transition['offset'];
            $candidate = CarbonImmutable::createFromTimestampUTC($wall->getTimestamp() - $offset);

            if ($candidate->setTimezone($timezone)->format('Y-m-d\TH:i:s') === $value) {
                $matches[$candidate->getTimestamp()] = $candidate;
            }
        }

        if (count($matches) !== 1) {
            throw ValidationException::withMessages([
                $field => count($matches) === 0
                    ? 'The local time does not exist because of a daylight-saving transition.'
                    : 'The local time is ambiguous because of a daylight-saving transition.',
            ]);
        }

        return array_values($matches)[0];
    }
}
