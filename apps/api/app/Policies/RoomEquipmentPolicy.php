<?php

namespace App\Policies;

use App\Models\RoomEquipment;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class RoomEquipmentPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return RoomEquipment::class;
    }
}
