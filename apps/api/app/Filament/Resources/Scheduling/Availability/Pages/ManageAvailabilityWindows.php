<?php

namespace App\Filament\Resources\Scheduling\Availability\Pages;

use App\Filament\Resources\Scheduling\Availability\AvailabilityWindowResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageAvailabilityWindows extends ManageRecords
{
    protected static string $resource = AvailabilityWindowResource::class;

    protected function getHeaderActions(): array
    {
        return [AvailabilityWindowResource::createAction()];
    }
}
