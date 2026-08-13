<?php

namespace App\Policies;

use App\Models\ServiceCategory;
use App\Policies\Concerns\AuthorizesSchedulingRecords;

final class ServiceCategoryPolicy
{
    use AuthorizesSchedulingRecords;

    protected function modelClass(): string
    {
        return ServiceCategory::class;
    }
}
