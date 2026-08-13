<?php

namespace App\Filament\Resources\People\Pages;

use App\Actions\People\CreatePerson as CreatePersonAction;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Resources\People\Schemas\PersonFormData;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePerson extends CreateRecord
{
    protected static string $resource = PersonResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreatePersonAction::class)->handle(
            PersonFormData::forWrite($data),
            PersonResource::user(),
        );
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Person added';
    }
}
