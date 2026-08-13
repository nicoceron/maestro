<?php

namespace App\Policies;

use App\Models\Location;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class LocationPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return Location::class;
    }
}
