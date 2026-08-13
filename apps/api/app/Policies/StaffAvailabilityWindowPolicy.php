<?php

namespace App\Policies;

use App\Models\StaffAvailabilityWindow;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class StaffAvailabilityWindowPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return StaffAvailabilityWindow::class;
    }
}
