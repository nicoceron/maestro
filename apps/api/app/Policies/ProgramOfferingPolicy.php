<?php

namespace App\Policies;

use App\Models\ProgramOffering;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class ProgramOfferingPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return ProgramOffering::class;
    }
}
