<?php

namespace App\Contracts\Scheduling;

use App\Enums\LocalTimeResolution;
use App\Support\Scheduling\OccurrenceSeed;
use Carbon\CarbonImmutable;

interface RecurrenceEngine
{
    public function canonicalize(?string $rule): ?string;

    /** @return list<OccurrenceSeed> */
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
    ): array;
}
