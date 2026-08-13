<?php

namespace App\Filament\Resources\Scheduling\ServicePrices\Pages;

use App\Filament\Resources\Scheduling\ServicePrices\ServicePriceResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageServicePrices extends ManageRecords
{
    protected static string $resource = ServicePriceResource::class;

    protected function getHeaderActions(): array
    {
        return [ServicePriceResource::createAction()];
    }
}
