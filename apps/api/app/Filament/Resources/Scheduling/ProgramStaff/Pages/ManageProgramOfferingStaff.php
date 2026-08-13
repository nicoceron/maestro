<?php

namespace App\Filament\Resources\Scheduling\ProgramStaff\Pages;

use App\Filament\Resources\Scheduling\ProgramStaff\ProgramOfferingStaffResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageProgramOfferingStaff extends ManageRecords
{
    protected static string $resource = ProgramOfferingStaffResource::class;

    protected function getHeaderActions(): array
    {
        return [ProgramOfferingStaffResource::createAction()];
    }
}
