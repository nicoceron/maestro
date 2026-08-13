<?php

namespace App\Policies;

use App\Models\Service;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class ServicePolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return Service::class;
    }
}
