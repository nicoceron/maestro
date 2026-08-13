<?php

namespace App\Filament\Resources\Scheduling\Locations\Pages;

use App\Filament\Resources\Scheduling\Locations\LocationResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageLocations extends ManageRecords
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [LocationResource::createAction()];
    }
}
