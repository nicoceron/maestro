<?php

namespace App\Filament\Resources\Scheduling\Programs;

use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\Programs\Pages\ManageProgramOfferings;
use App\Models\Location;
use App\Models\ProgramOffering;
use App\Models\Service;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

final class ProgramOfferingResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = ProgramOffering::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Program offerings';

    protected static ?string $slug = 'program-offerings';

    protected static ?int $navigationSort = 15;

    protected static function schedulingType(): string
    {
        return 'program-offerings';
    }

    public static function form(Schema $schema): Schema
    {
        $tenantId = fn (): string => (string) self::tenant()->getKey();

        return $schema->components([
            Select::make('service_id')->required()->searchable()
                ->options(fn (): array => Service::query()->where('studio_id', $tenantId())
                    ->where('active', true)->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('name')->required()->maxLength(140),
            Select::make('location_id')->searchable()->nullable()
                ->options(fn (): array => Location::query()->where('studio_id', $tenantId())
                    ->where('active', true)->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('timezone')->required()->default(fn (): string => self::tenant()->timezone),
            Textarea::make('description')->maxLength(5000)->columnSpanFull(),
            TextInput::make('duration_minutes')->numeric()->minValue(5)->maxValue(1440)
                ->helperText('Leave blank to inherit the service duration.'),
            TextInput::make('capacity')->numeric()->minValue(1)->maxValue(1000)
                ->helperText('Leave blank to inherit the service capacity.'),
            TextInput::make('price_minor')->label('Price override (minor units)')->numeric()->minValue(0),
            TextInput::make('currency')->length(3)->requiredWith('price_minor'),
            DatePicker::make('starts_on'),
            DatePicker::make('ends_on')->afterOrEqual('starts_on'),
            Toggle::make('enrollment_open')->default(true),
            Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->weight('medium'),
            TextColumn::make('service.name')->sortable(),
            TextColumn::make('location.name')->placeholder('Any location'),
            TextColumn::make('capacity')->placeholder('Inherited'),
            IconColumn::make('enrollment_open')->boolean(),
            IconColumn::make('active')->boolean(),
        ])->filters([TernaryFilter::make('active')->placeholder('All offerings')])
            ->recordActions([self::editAction()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageProgramOfferings::route('/')];
    }
}
