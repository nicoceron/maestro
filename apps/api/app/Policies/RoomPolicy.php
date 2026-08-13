<?php

namespace App\Policies;

use App\Models\Room;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class RoomPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return Room::class;
    }
}
