<?php

namespace App\Policies;

use App\Models\StaffTravelBuffer;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class StaffTravelBufferPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return StaffTravelBuffer::class;
    }
}
