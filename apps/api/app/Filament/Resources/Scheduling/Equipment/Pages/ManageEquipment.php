<?php

namespace App\Filament\Resources\Scheduling\Equipment\Pages;

use App\Filament\Resources\Scheduling\Equipment\EquipmentResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageEquipment extends ManageRecords
{
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [EquipmentResource::createAction()];
    }
}
