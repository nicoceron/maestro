<?php

namespace App\Filament\Resources\Scheduling\ProgramOverrides\Pages;

use App\Filament\Resources\Scheduling\ProgramOverrides\ProgramOfferingOverrideResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageProgramOfferingOverrides extends ManageRecords
{
    protected static string $resource = ProgramOfferingOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [ProgramOfferingOverrideResource::createAction()];
    }
}
