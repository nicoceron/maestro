<?php

namespace App\Models\Concerns;

use DateTimeZone;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

trait IsSchedulingRecord
{
    protected static function bootIsSchedulingRecord(): void
    {
        static::saving(function ($record): void {
            if ($record->isFillable('name') && $record->name !== null) {
                $name = Str::squish((string) $record->name);

                if ($name === '') {
                    throw ValidationException::withMessages(['name' => 'The name field is required.']);
                }

                $record->name = $name;

                if ($record->isFillable('normalized_name')) {
                    $record->normalized_name = mb_strtolower($name);
                }
            }

            if ($record->isFillable('currency') && filled($record->currency)) {
                $record->currency = mb_strtoupper((string) $record->currency);
            }

            if ($record->isFillable('country_code') && filled($record->country_code)) {
                $record->country_code = mb_strtoupper((string) $record->country_code);
            }

            if ($record->isFillable('color') && filled($record->color)) {
                $record->color = mb_strtoupper((string) $record->color);
            }

            if ($record->isFillable('timezone')
                && ! in_array($record->timezone, DateTimeZone::listIdentifiers(), true)) {
                throw ValidationException::withMessages([
                    'timezone' => 'The timezone must be an IANA timezone identifier.',
                ]);
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Scheduling records must be retired instead of deleted.');
        });
    }
}
