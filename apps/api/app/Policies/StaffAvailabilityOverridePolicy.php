<?php

namespace App\Policies;

use App\Models\StaffAvailabilityOverride;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class StaffAvailabilityOverridePolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return StaffAvailabilityOverride::class;
    }
}
