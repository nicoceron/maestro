<?php

namespace App\Filament\Resources\Scheduling\TravelBuffers\Pages;

use App\Filament\Resources\Scheduling\TravelBuffers\TravelBufferResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageTravelBuffers extends ManageRecords
{
    protected static string $resource = TravelBufferResource::class;

    protected function getHeaderActions(): array
    {
        return [TravelBufferResource::createAction()];
    }
}
