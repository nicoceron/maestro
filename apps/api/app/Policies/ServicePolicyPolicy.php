<?php

namespace App\Policies;

use App\Models\ServicePolicy;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class ServicePolicyPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return ServicePolicy::class;
    }
}
