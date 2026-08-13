<?php

namespace App\Policies;

use App\Models\StaffAccountLink;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class StaffAccountLinkPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return StaffAccountLink::class;
    }
}
