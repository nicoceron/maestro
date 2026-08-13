<?php

namespace App\Filament\Resources\Scheduling\Services;

use App\Enums\MakeupPolicy;
use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\Services\Pages\ManageServices;
use App\Models\Service;
use App\Models\ServiceCategory;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

final class ServiceResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMusicalNote;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Programs & services';

    protected static ?string $slug = 'services';

    protected static ?int $navigationSort = 10;

    protected static function schedulingType(): string
    {
        return 'services';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Service')->schema([
                Select::make('service_category_id')->label('Category')->required()->searchable()
                    ->options(fn (): array => ServiceCategory::query()
                        ->where('studio_id', self::tenant()->getKey())->where('active', true)
                        ->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('name')->required()->maxLength(120),
                Textarea::make('description')->maxLength(5000)->columnSpanFull(),
                TextInput::make('default_duration_minutes')->label('Duration (minutes)')
                    ->required()->numeric()->minValue(5)->maxValue(1440),
                TextInput::make('default_capacity')->required()->numeric()->minValue(1)->maxValue(1000)->default(1),
            ])->columns(2),
            Section::make('Default price and policy')->schema([
                TextInput::make('default_price_minor')->label('Price (minor units)')
                    ->helperText('For example, $125.00 USD is 12500.')->required()->numeric()->minValue(0),
                TextInput::make('currency')->required()->length(3)->default(fn (): string => self::tenant()->currency),
                TextInput::make('booking_lead_minutes')->numeric()->minValue(0)->default(0),
                TextInput::make('cancellation_notice_minutes')->numeric()->minValue(0)->default(0),
                Select::make('makeup_policy')->options(MakeupPolicy::class)->default(MakeupPolicy::None)->required(),
                Toggle::make('active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->weight('medium'),
            TextColumn::make('category.name')->label('Category')->sortable(),
            TextColumn::make('default_duration_minutes')->suffix(' min')->label('Duration'),
            TextColumn::make('default_capacity')->label('Capacity'),
            TextColumn::make('default_price_minor')->label('Default price')
                ->formatStateUsing(fn (int $state, Service $record): string => number_format($state / 100, 2).' '.$record->currency),
            IconColumn::make('active')->boolean(),
        ])->filters([TernaryFilter::make('active')->placeholder('All services')])
            ->recordActions([self::editAction()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageServices::route('/')];
    }
}
