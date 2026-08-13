<?php

namespace App\Filament\Resources\Scheduling\TimeOff\Pages;

use App\Filament\Resources\Scheduling\TimeOff\TimeOffResource;
use Filament\Resources\Pages\ManageRecords;

final class ManageTimeOff extends ManageRecords
{
    protected static string $resource = TimeOffResource::class;

    protected function getHeaderActions(): array
    {
        return [TimeOffResource::createAction()];
    }
}
