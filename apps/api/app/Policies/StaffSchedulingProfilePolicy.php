<?php

namespace App\Policies;

use App\Models\StaffSchedulingProfile;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class StaffSchedulingProfilePolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return StaffSchedulingProfile::class;
    }
}
