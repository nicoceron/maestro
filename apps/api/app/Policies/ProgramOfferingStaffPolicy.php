<?php

namespace App\Policies;

use App\Models\ProgramOfferingStaff;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class ProgramOfferingStaffPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return ProgramOfferingStaff::class;
    }
}
