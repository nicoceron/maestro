<?php

namespace App\Filament\Resources\Scheduling\Programs\Pages;

use App\Filament\Resources\Scheduling\Programs\ProgramOfferingResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageProgramOfferings extends ManageRecords
{
    protected static string $resource = ProgramOfferingResource::class;

    protected function getHeaderActions(): array
    {
        return [ProgramOfferingResource::createAction()];
    }
}
