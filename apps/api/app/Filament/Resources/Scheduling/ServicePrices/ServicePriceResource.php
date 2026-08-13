<?php

namespace App\Filament\Resources\Scheduling\ServicePrices;

use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\ServicePrices\Pages\ManageServicePrices;
use App\Models\Service;
use App\Models\ServicePrice;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

final class ServicePriceResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = ServicePrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Price schedules';

    protected static ?string $slug = 'service-prices';

    protected static ?int $navigationSort = 21;

    protected static function schedulingType(): string
    {
        return 'service-prices';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('service_id')->label('Service')->required()->searchable()->options(self::serviceOptions()),
            TextInput::make('amount_minor')->label('Price (minor units)')->required()->numeric()->minValue(0)
                ->helperText('For example, $125.00 USD is 12500.'),
            TextInput::make('currency')->required()->length(3)->default(fn (): string => self::tenant()->currency),
            DatePicker::make('effective_from')->required()->default(now()),
            DatePicker::make('effective_until')->afterOrEqual('effective_from')
                ->helperText('Leave blank for an open-ended schedule.'),
            Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('service.name')->searchable()->sortable()->weight('medium'),
            TextColumn::make('amount_minor')->label('Price')
                ->formatStateUsing(fn (int $state, ServicePrice $record): string => number_format($state / 100, 2).' '.$record->currency),
            TextColumn::make('effective_from')->date()->sortable(),
            TextColumn::make('effective_until')->date()->placeholder('No end date'),
            IconColumn::make('active')->boolean(),
        ])->filters([
            SelectFilter::make('service_id')->label('Service')->options(self::serviceOptions()),
            TernaryFilter::make('active')->placeholder('All schedules'),
        ])->recordActions([self::editAction()])->defaultSort('effective_from', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ManageServicePrices::route('/')];
    }

    /** @return \Closure(): array<string, string> */
    private static function serviceOptions(): \Closure
    {
        return fn (): array => Service::query()->where('studio_id', self::tenant()->getKey())
            ->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
