<?php

namespace App\Filament\Resources\People\Schemas;

use App\Enums\PersonStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\People\PersonResource;
use App\Models\GuardianRelationship;
use App\Models\Person;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

final class PersonInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile')
                    ->schema([
                        TextEntry::make('display_name')
                            ->label('Name')
                            ->state(fn (Person $record): string => $record->displayName()),
                        TextEntry::make('preferred_name')
                            ->label('Preferred name')
                            ->placeholder('—'),
                        TextEntry::make('pronouns')
                            ->placeholder('—')
                            ->visible(PersonResource::canViewPrivateProfile()),
                        TextEntry::make('birth_date')
                            ->date()
                            ->placeholder('—')
                            ->visible(PersonResource::canViewPrivateProfile()),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (PersonStatus|string $state): string => Str::headline(
                                $state instanceof PersonStatus ? $state->value : $state,
                            )),
                    ])
                    ->columns(2),
                Section::make('Contact details')
                    ->schema([
                        TextEntry::make('email')
                            ->state(fn (Person $record): ?string => PersonResource::canViewContactDetails($record)
                                ? $record->email
                                : null)
                            ->placeholder('Not available'),
                        TextEntry::make('phone')
                            ->state(fn (Person $record): ?string => PersonResource::canViewContactDetails($record)
                                ? $record->phone
                                : null)
                            ->placeholder('Not available'),
                    ])
                    ->columns(2),
                Section::make('Student profile')
                    ->schema([
                        TextEntry::make('studentProfile.status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (StudentStatus|string $state): string => Str::headline(
                                $state instanceof StudentStatus ? $state->value : $state,
                            )),
                        TextEntry::make('studentProfile.school_grade')
                            ->label('School grade')
                            ->placeholder('—'),
                        TextEntry::make('studentProfile.joined_on')
                            ->label('Joined')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('studentProfile.left_on')
                            ->label('Left')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('studentProfile.lead_source')
                            ->label('Lead source')
                            ->placeholder('—'),
                        TextEntry::make('studentProfile.status_changed_at')
                            ->label('Status changed')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->visible(fn (Person $record): bool => PersonResource::canViewPrivateProfile()
                        && $record->studentProfile !== null),
                Section::make('Staff profile')
                    ->schema([
                        TextEntry::make('staffProfile.roles')
                            ->label('Roles')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                        TextEntry::make('staffProfile.status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => Str::headline($state->value ?? (string) $state)),
                        TextEntry::make('staffProfile.employment_type')
                            ->label('Employment type')
                            ->formatStateUsing(fn ($state): string => Str::headline($state->value ?? (string) $state))
                            ->placeholder('—'),
                        IconEntry::make('staffProfile.can_substitute')
                            ->label('Available as substitute')
                            ->boolean(),
                        TextEntry::make('staffProfile.hire_on')
                            ->label('Started')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('staffProfile.left_on')
                            ->label('Left')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('staffProfile.bio')
                            ->label('Biography')
                            ->columnSpanFull()
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->visible(fn (Person $record): bool => PersonResource::canViewPrivateProfile()
                        && $record->staffProfile !== null),
                Section::make('Instruments')
                    ->schema([
                        RepeatableEntry::make('instrumentAssignments')
                            ->label('')
                            ->schema([
                                TextEntry::make('instrument.name')->label('Instrument'),
                                TextEntry::make('relationship')
                                    ->formatStateUsing(fn ($state): string => Str::headline($state->value ?? (string) $state)),
                                TextEntry::make('proficiency')
                                    ->formatStateUsing(fn ($state): string => Str::headline($state->value ?? (string) $state))
                                    ->placeholder('—'),
                                IconEntry::make('is_primary')
                                    ->label('Primary')
                                    ->boolean(),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn (Person $record): bool => PersonResource::canViewPrivateProfile()
                        && $record->instrumentAssignments->isNotEmpty()),
                Section::make('Tags and custom fields')
                    ->schema([
                        TextEntry::make('tagAssignments.tag.name')
                            ->label('Tags')
                            ->badge()
                            ->placeholder('No tags'),
                        RepeatableEntry::make('customFieldValues')
                            ->label('Custom fields')
                            ->schema([
                                TextEntry::make('definition.name')->label('Field'),
                                TextEntry::make('value')
                                    ->formatStateUsing(fn (mixed $state): string => self::formatCustomValue($state))
                                    ->placeholder('—'),
                            ])
                            ->columns(2)
                            ->visible(fn (Person $record): bool => $record->customFieldValues->isNotEmpty()),
                    ])
                    ->visible(PersonResource::canViewPrivateProfile()),
                Section::make('Households')
                    ->schema([
                        TextEntry::make('householdMemberships.household.name')
                            ->label('Family or household')
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('Not linked to a household')
                            ->visible(fn (Person $record): bool => PersonResource::canViewPrivateProfile()
                                || PersonResource::canViewContactDetails($record)),
                        TextEntry::make('guardian_contacts')
                            ->label('Guardians')
                            ->state(fn (Person $record): array => GuardianRelationship::query()
                                ->where('studio_id', $record->studio_id)
                                ->where('student_person_id', $record->getKey())
                                ->with('guardian:id,studio_id,first_name,last_name,preferred_name')
                                ->get()
                                ->map(fn (GuardianRelationship $relationship): string => sprintf(
                                    '%s · %s',
                                    $relationship->guardian->displayName(),
                                    Str::headline($relationship->relationship->value),
                                ))
                                ->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('No guardian relationships')
                            ->visible(PersonResource::canViewPrivateProfile()),
                    ])
                    ->visible(fn (Person $record): bool => PersonResource::canViewPrivateProfile()
                        || PersonResource::canViewContactDetails($record)),
                Section::make('Student status history')
                    ->schema([
                        RepeatableEntry::make('studentStatusTransitions')
                            ->label('')
                            ->schema([
                                TextEntry::make('new_status')
                                    ->label('New status')
                                    ->badge()
                                    ->formatStateUsing(fn ($state): string => Str::headline($state->value ?? (string) $state)),
                                TextEntry::make('previous_status')
                                    ->label('Previous status')
                                    ->formatStateUsing(fn ($state): string => Str::headline($state->value ?? (string) $state)),
                                TextEntry::make('occurred_at')
                                    ->label('Changed')
                                    ->dateTime(),
                                TextEntry::make('actor.name')
                                    ->label('Changed by')
                                    ->placeholder('System'),
                                TextEntry::make('reason')
                                    ->columnSpanFull()
                                    ->placeholder('No reason recorded'),
                            ])
                            ->columns(4),
                    ])
                    ->visible(fn (Person $record): bool => PersonResource::canViewPrivateProfile()
                        && $record->studentStatusTransitions->isNotEmpty()),
            ]);
    }

    private static function formatCustomValue(mixed $state): string
    {
        if (! is_array($state)) {
            return is_bool($state) ? ($state ? 'Yes' : 'No') : (string) $state;
        }

        $value = $state['value'] ?? $state;

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return is_array($value)
            ? collect($value)->map(fn (mixed $item): string => (string) $item)->join(', ')
            : (string) $value;
    }
}
