<?php

namespace App\Filament\Resources\Scheduling\ProgramOverrides;

use App\Enums\MakeupPolicy;
use App\Filament\Resources\Scheduling\Availability\Support\TeacherOptions;
use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\ProgramOverrides\Pages\ManageProgramOfferingOverrides;
use App\Models\Location;
use App\Models\ProgramOffering;
use App\Models\ProgramOfferingOverride;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

final class ProgramOfferingOverrideResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = ProgramOfferingOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Program overrides';

    protected static ?string $slug = 'program-overrides';

    protected static ?int $navigationSort = 17;

    protected static function schedulingType(): string
    {
        return 'program-offering-overrides';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Scope')->schema([
                Select::make('program_offering_id')->label('Program offering')->required()->searchable()->options(self::offeringOptions()),
                Select::make('staff_profile_id')->label('Teacher')->searchable()->nullable()
                    ->options(fn (): array => TeacherOptions::for(auth()->user(), self::tenant())),
                Select::make('location_id')->label('Location')->searchable()->nullable()->options(self::locationOptions()),
                DatePicker::make('effective_from')->required()->default(now()),
                DatePicker::make('effective_until')->afterOrEqual('effective_from'),
            ])->columns(2),
            Section::make('Inherited value overrides')->description('Leave a field blank to inherit its next-most-specific value.')->schema([
                TextInput::make('duration_minutes')->numeric()->minValue(5)->maxValue(1440),
                TextInput::make('capacity')->numeric()->minValue(1)->maxValue(1000),
                TextInput::make('price_minor')->label('Price (minor units)')->numeric()->minValue(0),
                TextInput::make('currency')->length(3)->requiredWith('price_minor'),
                TextInput::make('booking_lead_minutes')->numeric()->minValue(0),
                TextInput::make('cancellation_notice_minutes')->numeric()->minValue(0),
                Select::make('makeup_policy')->options(MakeupPolicy::class)->nullable(),
                Toggle::make('active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('offering.name')->label('Program')->searchable()->sortable()->weight('medium'),
            TextColumn::make('staffProfile.person.first_name')->label('Teacher')
                ->formatStateUsing(fn (?string $state, ProgramOfferingOverride $record): string => $record->staffProfile?->person?->displayName() ?? 'Any teacher'),
            TextColumn::make('location.name')->placeholder('Any location'),
            TextColumn::make('effective_from')->date()->sortable(),
            TextColumn::make('effective_until')->date()->placeholder('No end date'),
            IconColumn::make('active')->boolean(),
        ])->filters([
            SelectFilter::make('program_offering_id')->label('Program')->options(self::offeringOptions()),
            SelectFilter::make('location_id')->label('Location')->options(self::locationOptions()),
            TernaryFilter::make('active')->placeholder('All overrides'),
        ])->recordActions([self::editAction()])->defaultSort('effective_from', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageProgramOfferingOverrides::route('/')];
    }

    /** @return \Closure(): array<string, string> */
    private static function offeringOptions(): \Closure
    {
        return fn (): array => ProgramOffering::query()->where('studio_id', self::tenant()->getKey())
            ->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return \Closure(): array<string, string> */
    private static function locationOptions(): \Closure
    {
        return fn (): array => Location::query()->where('studio_id', self::tenant()->getKey())
            ->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
