<?php

namespace App\Filament\Resources\Scheduling\ProgramStaff;

use App\Filament\Resources\Scheduling\Availability\Support\TeacherOptions;
use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\ProgramStaff\Pages\ManageProgramOfferingStaff;
use App\Models\ProgramOffering;
use App\Models\ProgramOfferingStaff;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

final class ProgramOfferingStaffResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = ProgramOfferingStaff::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Program teachers';

    protected static ?string $slug = 'program-teachers';

    protected static ?int $navigationSort = 16;

    protected static function schedulingType(): string
    {
        return 'program-offering-staff';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('program_offering_id')->label('Program offering')->required()->searchable()->options(self::offeringOptions()),
            Select::make('staff_profile_id')->label('Teacher')->required()->searchable()
                ->options(fn (): array => TeacherOptions::for(auth()->user(), self::tenant())),
            Toggle::make('is_primary')->label('Primary teacher')->default(false),
            Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('offering.name')->label('Program')->searchable()->sortable()->weight('medium'),
            TextColumn::make('staffProfile.person.first_name')->label('Teacher')
                ->formatStateUsing(fn (ProgramOfferingStaff $record): string => $record->staffProfile->person->displayName()),
            IconColumn::make('is_primary')->label('Primary')->boolean(),
            IconColumn::make('active')->boolean(),
        ])->filters([
            SelectFilter::make('program_offering_id')->label('Program')->options(self::offeringOptions()),
            TernaryFilter::make('active')->placeholder('All assignments'),
        ])->recordActions([self::editAction()])->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => ManageProgramOfferingStaff::route('/')];
    }

    /** @return \Closure(): array<string, string> */
    private static function offeringOptions(): \Closure
    {
        return fn (): array => ProgramOffering::query()->where('studio_id', self::tenant()->getKey())
            ->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
