<?php

namespace App\Filament\Resources\People\Pages;

use App\Actions\People\UpdatePerson;
use App\Filament\Resources\People\PersonResource;
use App\Filament\Resources\People\Schemas\PersonFormData;
use App\Models\Person;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditPerson extends EditRecord
{
    protected static string $resource = PersonResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Person $person */
        $person = $this->getRecord();

        return PersonFormData::fill($person);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        abort_unless($record instanceof Person, 404);

        try {
            return app(UpdatePerson::class)->handle(
                $record,
                PersonFormData::forWrite($data, $record),
                (int) ($data['version'] ?? 0),
                PersonResource::user(),
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key): array => [
                        "data.{$key}" => $messages,
                    ])
                    ->all(),
            );
        }
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Person updated';
    }
}
