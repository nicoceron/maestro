<?php

namespace App\Filament\Resources\Households\Schemas;

use App\Filament\Resources\Households\HouseholdResource;
use App\Models\HouseholdMember;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

final class HouseholdInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Household')
                ->schema([
                    TextEntry::make('name')->label('Household name'),
                    TextEntry::make('notes')
                        ->placeholder('No internal notes')
                        ->visible(HouseholdResource::canViewPrivateHousehold()),
                    TextEntry::make('version')
                        ->label('Record version')
                        ->visible(HouseholdResource::canViewPrivateHousehold()),
                ]),
            Section::make('People')
                ->schema([
                    RepeatableEntry::make('members')
                        ->label('')
                        ->schema([
                            TextEntry::make('person_name')
                                ->label('Name')
                                ->state(fn (HouseholdMember $record): string => $record->person->displayName()),
                            TextEntry::make('role')
                                ->formatStateUsing(fn ($state): string => Str::headline($state->value ?? (string) $state))
                                ->badge(),
                            TextEntry::make('person.email')
                                ->label('Email')
                                ->placeholder('—')
                                ->visible(fn (HouseholdMember $record): bool => HouseholdResource::canViewPrivateHousehold()
                                    || $record->receives_billing),
                            TextEntry::make('person.phone')
                                ->label('Phone')
                                ->placeholder('—')
                                ->visible(fn (HouseholdMember $record): bool => HouseholdResource::canViewPrivateHousehold()
                                    || $record->receives_billing),
                            IconEntry::make('is_primary_contact')
                                ->label('Primary contact')
                                ->boolean(),
                            IconEntry::make('receives_billing')
                                ->label('Receives billing')
                                ->boolean(),
                            TextEntry::make('person.birth_date')
                                ->label('Birth date')
                                ->date()
                                ->placeholder('—')
                                ->visible(HouseholdResource::canViewPrivateHousehold()),
                            TextEntry::make('person.studentProfile.status')
                                ->label('Student status')
                                ->formatStateUsing(fn ($state): string => Str::headline($state->value ?? (string) $state))
                                ->badge()
                                ->placeholder('Not a student')
                                ->visible(HouseholdResource::canViewPrivateHousehold()),
                        ])
                        ->columns(2),
                ]),
            Section::make('Guardian relationships')
                ->schema([
                    RepeatableEntry::make('guardianRelationships')
                        ->label('')
                        ->schema([
                            TextEntry::make('guardian_name')
                                ->label('Guardian')
                                ->state(fn ($record): string => $record->guardian->displayName()),
                            TextEntry::make('student_name')
                                ->label('Student')
                                ->state(fn ($record): string => $record->student->displayName()),
                            TextEntry::make('relationship')
                                ->formatStateUsing(fn ($state): string => Str::headline($state->value ?? (string) $state)),
                            IconEntry::make('is_legal_guardian')->label('Legal guardian')->boolean(),
                            IconEntry::make('is_emergency_contact')->label('Emergency contact')->boolean(),
                            IconEntry::make('is_authorized_pickup')->label('Authorized pickup')->boolean(),
                            TextEntry::make('portal_permissions')
                                ->label('Portal access')
                                ->badge()
                                ->formatStateUsing(fn (string $state): string => Str::headline($state)),
                        ])
                        ->columns(3),
                ])
                ->visible(HouseholdResource::canViewPrivateHousehold()),
        ]);
    }
}
