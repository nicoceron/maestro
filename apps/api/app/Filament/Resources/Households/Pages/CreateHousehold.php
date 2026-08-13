<?php

namespace App\Filament\Resources\Households\Pages;

use App\Actions\People\CreateHousehold as CreateHouseholdAction;
use App\Filament\Resources\Households\HouseholdResource;
use App\Filament\Resources\Households\Schemas\HouseholdFormData;
use App\Models\Household;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class CreateHousehold extends CreateRecord
{
    protected static string $resource = HouseholdResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $studio = HouseholdResource::tenant();
        Gate::forUser(HouseholdResource::user())->authorize('create', [Household::class, $studio]);

        return app(CreateHouseholdAction::class)->handle(
            HouseholdFormData::validated($data),
            HouseholdResource::user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Household added';
    }
}
