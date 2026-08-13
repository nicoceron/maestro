<?php

namespace App\Filament\Resources\Households\Pages;

use App\Filament\Resources\Households\HouseholdResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewHousehold extends ViewRecord
{
    protected static string $resource = HouseholdResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
