<?php

namespace App\Policies;

use App\Models\ServicePrice;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class ServicePricePolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return ServicePrice::class;
    }
}
