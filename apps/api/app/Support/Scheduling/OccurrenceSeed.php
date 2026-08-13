<?php

namespace App\Support\Scheduling;

use Carbon\CarbonImmutable;

final readonly class OccurrenceSeed
{
    public function __construct(
        public string $recurrenceIdLocal,
        public CarbonImmutable $startsAt,
        public int $utcOffsetSeconds,
    ) {}
}
