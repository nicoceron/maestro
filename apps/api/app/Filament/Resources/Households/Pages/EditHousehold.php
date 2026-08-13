<?php

namespace App\Filament\Resources\Households\Pages;

use App\Actions\People\UpdateHousehold as UpdateHouseholdAction;
use App\Filament\Resources\Households\HouseholdResource;
use App\Filament\Resources\Households\Schemas\HouseholdFormData;
use App\Models\Household;
use App\Models\HouseholdMember;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditHousehold extends EditRecord
{
    protected static string $resource = HouseholdResource::class;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Household $household */
        $household = $this->getRecord()->loadMissing([
            'members.person.studentProfile',
            'guardianRelationships',
        ]);

        $members = $household->members
            ->map(function (HouseholdMember $member): array {
                $person = $member->person;

                return [
                    'key' => (string) $person->getKey(),
                    'person_id' => (string) $person->getKey(),
                    'version' => $person->version,
                    'first_name' => $person->first_name,
                    'last_name' => $person->last_name,
                    'preferred_name' => $person->preferred_name,
                    'email' => $person->email,
                    'phone' => $person->phone,
                    'birth_date' => $person->birth_date,
                    'pronouns' => $person->pronouns,
                    'household_role' => $member->role->value,
                    'is_primary_contact' => $member->is_primary_contact,
                    'receives_billing' => $member->receives_billing,
                    'student' => $person->studentProfile === null ? null : [
                        'status' => $person->studentProfile->status->value,
                        'joined_on' => $person->studentProfile->joined_on,
                        'school_grade' => $person->studentProfile->school_grade,
                    ],
                ];
            })
            ->values()
            ->all();

        return [
            'name' => $household->name,
            'notes' => $household->notes,
            'version' => $household->version,
            'members' => $members,
            'relationships' => $household->guardianRelationships
                ->map(fn ($relationship): array => [
                    'guardian_key' => (string) $relationship->guardian_person_id,
                    'student_key' => (string) $relationship->student_person_id,
                    'relationship' => $relationship->relationship->value,
                    'is_legal_guardian' => $relationship->is_legal_guardian,
                    'is_emergency_contact' => $relationship->is_emergency_contact,
                    'is_authorized_pickup' => $relationship->is_authorized_pickup,
                    'portal_permissions' => $relationship->portal_permissions,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $attributes = HouseholdFormData::validated($data);
        $expectedVersion = (int) ($attributes['version'] ?? 0);
        unset($attributes['version']);

        try {
            return app(UpdateHouseholdAction::class)->handle(
                $record,
                $attributes,
                $expectedVersion,
                HouseholdResource::user(),
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

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Household updated';
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }
}
