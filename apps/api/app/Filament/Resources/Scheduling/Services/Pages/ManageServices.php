<?php

namespace App\Filament\Resources\Scheduling\Services\Pages;

use App\Filament\Resources\Scheduling\Services\ServiceResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageServices extends ManageRecords
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [ServiceResource::createAction()];
    }
}
