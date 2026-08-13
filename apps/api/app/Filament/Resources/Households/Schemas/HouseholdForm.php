<?php

namespace App\Filament\Resources\Households\Schemas;

use App\Enums\GuardianRelationshipType;
use App\Enums\HouseholdMemberRole;
use App\Enums\PortalPermission;
use App\Enums\StudentStatus;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

final class HouseholdForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Hidden::make('version'),
            Section::make('Household')
                ->schema([
                    TextInput::make('name')
                        ->label('Household name')
                        ->required()
                        ->maxLength(160)
                        ->placeholder('For example, Rivera household'),
                    Textarea::make('notes')
                        ->maxLength(5000)
                        ->rows(4)
                        ->helperText('Internal notes are visible only to authorized studio staff.'),
                ]),
            Section::make('People')
                ->description('Add every guardian, adult student, learner, payer, or other contact who belongs to this household. Choose exactly one primary contact.')
                ->schema([
                    Repeater::make('members')
                        ->label('Household members')
                        ->schema([
                            Hidden::make('key')
                                ->default(fn (): string => Str::lower(Str::random(16))),
                            Hidden::make('person_id'),
                            Hidden::make('version'),
                            TextInput::make('first_name')
                                ->label('First name')
                                ->required()
                                ->maxLength(100),
                            TextInput::make('last_name')
                                ->label('Last name')
                                ->maxLength(100),
                            TextInput::make('preferred_name')
                                ->label('Preferred name')
                                ->maxLength(100),
                            Select::make('household_role')
                                ->label('Household role')
                                ->options(self::enumOptions(HouseholdMemberRole::cases()))
                                ->required()
                                ->native(false)
                                ->live(),
                            TextInput::make('email')
                                ->email()
                                ->maxLength(254),
                            TextInput::make('phone')
                                ->tel()
                                ->maxLength(40),
                            DatePicker::make('birth_date')
                                ->label('Birth date')
                                ->maxDate(now()->toDateString())
                                ->native(false),
                            TextInput::make('pronouns')
                                ->maxLength(60),
                            Toggle::make('is_primary_contact')
                                ->label('Primary contact')
                                ->helperText('Exactly one person must be the primary household contact.'),
                            Toggle::make('receives_billing')
                                ->label('Receives billing'),
                            Select::make('student.status')
                                ->label('Student status')
                                ->options(self::enumOptions(StudentStatus::cases()))
                                ->default(StudentStatus::Lead->value)
                                ->required(fn (Get $get): bool => $get('household_role') === HouseholdMemberRole::Learner->value)
                                ->visible(fn (Get $get): bool => $get('household_role') === HouseholdMemberRole::Learner->value)
                                ->disabled(fn (Get $get): bool => filled($get('../../person_id')))
                                ->dehydrated()
                                ->native(false),
                            DatePicker::make('student.joined_on')
                                ->label('Joined on')
                                ->native(false)
                                ->visible(fn (Get $get): bool => $get('household_role') === HouseholdMemberRole::Learner->value),
                            TextInput::make('student.school_grade')
                                ->label('School grade')
                                ->maxLength(60)
                                ->visible(fn (Get $get): bool => $get('household_role') === HouseholdMemberRole::Learner->value),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->maxItems(20)
                        ->defaultItems(2)
                        ->reorderable(false)
                        ->addActionLabel('Add household member')
                        ->itemLabel(fn (array $state): ?string => filled($state['first_name'] ?? null)
                            ? trim(($state['preferred_name'] ?: $state['first_name']).' '.($state['last_name'] ?? ''))
                            : null)
                        ->columnSpanFull(),
                ]),
            Section::make('Guardian relationships')
                ->description('Connect guardians to learners and choose only the portal areas each guardian should access. Legal guardian, emergency contact, and pickup authority are separate decisions.')
                ->schema([
                    Repeater::make('relationships')
                        ->label('Guardian-to-student relationships')
                        ->schema([
                            Select::make('guardian_key')
                                ->label('Guardian')
                                ->options(fn (Get $get): array => self::memberOptions(
                                    $get('../../members'),
                                    HouseholdMemberRole::Guardian,
                                ))
                                ->required()
                                ->native(false),
                            Select::make('student_key')
                                ->label('Student')
                                ->options(fn (Get $get): array => self::memberOptions(
                                    $get('../../members'),
                                    HouseholdMemberRole::Learner,
                                ))
                                ->required()
                                ->native(false),
                            Select::make('relationship')
                                ->options(self::enumOptions(GuardianRelationshipType::cases()))
                                ->required()
                                ->native(false),
                            Toggle::make('is_legal_guardian')
                                ->label('Legal guardian'),
                            Toggle::make('is_emergency_contact')
                                ->label('Emergency contact'),
                            Toggle::make('is_authorized_pickup')
                                ->label('Authorized pickup'),
                            CheckboxList::make('portal_permissions')
                                ->label('Portal access')
                                ->options(self::enumOptions(PortalPermission::cases()))
                                ->default(PortalPermission::defaults())
                                ->columns(2)
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->maxItems(40)
                        ->reorderable(false)
                        ->addActionLabel('Add guardian relationship')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    /** @return array<string, string> */
    private static function memberOptions(mixed $members, HouseholdMemberRole $role): array
    {
        if (! is_array($members)) {
            return [];
        }

        return collect($members)
            ->filter(fn (mixed $member): bool => is_array($member)
                && ($member['household_role'] ?? null) === $role->value
                && filled($member['key'] ?? null))
            ->mapWithKeys(function (array $member): array {
                $name = trim(
                    (($member['preferred_name'] ?? null) ?: ($member['first_name'] ?? '')).' '.($member['last_name'] ?? ''),
                );

                return [(string) $member['key'] => $name !== '' ? $name : 'Unnamed person'];
            })
            ->all();
    }

    /** @param list<\BackedEnum> $cases @return array<string, string> */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn (\BackedEnum $case): array => [
            (string) $case->value => Str::headline((string) $case->value),
        ])->all();
    }
}
