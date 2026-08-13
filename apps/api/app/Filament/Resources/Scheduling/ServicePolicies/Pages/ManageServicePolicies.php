<?php

namespace App\Filament\Resources\Scheduling\ServicePolicies\Pages;

use App\Filament\Resources\Scheduling\ServicePolicies\ServicePolicyResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageServicePolicies extends ManageRecords
{
    protected static string $resource = ServicePolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [ServicePolicyResource::createAction()];
    }
}
