<?php

namespace App\Filament\Resources\Scheduling\Equipment;

use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\Equipment\Pages\ManageEquipment;
use App\Models\Equipment;
use App\Models\Location;
use App\Models\Room;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

final class EquipmentResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = Equipment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Equipment';

    protected static ?string $slug = 'equipment';

    protected static ?int $navigationSort = 32;

    protected static function schedulingType(): string
    {
        return 'equipment';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location_id')->required()->searchable()->live()
                ->options(fn (): array => Location::query()->where('studio_id', self::tenant()->getKey())
                    ->where('active', true)->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('room_id')->searchable()->nullable()
                ->options(fn (Get $get): array => blank($get('location_id')) ? [] : Room::query()
                    ->where('studio_id', self::tenant()->getKey())->where('location_id', $get('location_id'))
                    ->where('active', true)->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('name')->required()->maxLength(120),
            TextInput::make('quantity')->required()->numeric()->minValue(1)->maxValue(1000)->default(1),
            Textarea::make('notes')->maxLength(5000)->columnSpanFull()
                ->helperText('Internal equipment notes are hidden from teacher catalog responses.'),
            Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->weight('medium'),
            TextColumn::make('location.name')->sortable(),
            TextColumn::make('room.name')->placeholder('Shared at location'),
            TextColumn::make('quantity')->label('Available')->sortable(),
            IconColumn::make('active')->boolean(),
        ])->filters([TernaryFilter::make('active')->placeholder('All equipment')])
            ->recordActions([self::editAction()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageEquipment::route('/')];
    }
}
