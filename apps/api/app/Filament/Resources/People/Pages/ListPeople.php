<?php

namespace App\Filament\Resources\People\Pages;

use App\Enums\PersonStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\People\PersonResource;
use App\Models\Person;
use App\Models\StudentProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ListPeople extends ListRecords
{
    protected static string $resource = PersonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addStudent')
                ->label('Add student')
                ->icon('heroicon-o-user-plus')
                ->visible(fn (): bool => PersonResource::canCreate())
                ->schema([
                    TextInput::make('first_name')->required()->maxLength(100),
                    TextInput::make('last_name')->maxLength(100),
                    TextInput::make('preferred_name')->maxLength(100),
                    TextInput::make('email')->email()->maxLength(254),
                    TextInput::make('phone')->tel()->maxLength(40),
                    DatePicker::make('birth_date')->maxDate(now()),
                    Select::make('student_status')
                        ->options(PersonResource::studentStatusLabels())
                        ->default(StudentStatus::Lead->value)
                        ->required(),
                    TextInput::make('school_grade')->maxLength(60),
                ])
                ->action(function (array $data): void {
                    $studio = PersonResource::tenant();
                    Gate::authorize('create', [Person::class, $studio]);
                    $studentStatus = StudentStatus::tryFrom((string) $data['student_status']);

                    if ($studentStatus === null) {
                        throw ValidationException::withMessages([
                            'student_status' => 'Choose a valid student status.',
                        ]);
                    }

                    DB::transaction(function () use ($data, $studio, $studentStatus): void {
                        $person = Person::query()->create([
                            'studio_id' => $studio->getKey(),
                            'first_name' => $data['first_name'],
                            'last_name' => $data['last_name'] ?? null,
                            'preferred_name' => $data['preferred_name'] ?? null,
                            'email' => $data['email'] ?? null,
                            'phone' => $data['phone'] ?? null,
                            'birth_date' => $data['birth_date'] ?? null,
                            'status' => PersonStatus::Active,
                        ]);

                        StudentProfile::query()->create([
                            'studio_id' => $studio->getKey(),
                            'person_id' => $person->getKey(),
                            'status' => $studentStatus,
                            'joined_on' => $studentStatus === StudentStatus::Active ? now()->toDateString() : null,
                            'school_grade' => $data['school_grade'] ?? null,
                            'learning_preferences' => [],
                        ]);
                    });

                    $this->resetTable();
                    Notification::make()
                        ->success()
                        ->title('Student added')
                        ->send();
                }),
        ];
    }
}
