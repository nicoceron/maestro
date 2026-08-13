<?php

namespace App\Policies;

use App\Models\ProgramOfferingOverride;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class ProgramOfferingOverridePolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return ProgramOfferingOverride::class;
    }
}
