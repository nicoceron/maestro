<?php

namespace App\Policies;

use App\Models\Equipment;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class EquipmentPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return Equipment::class;
    }
}
