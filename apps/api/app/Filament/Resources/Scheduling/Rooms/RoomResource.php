<?php

namespace App\Filament\Resources\Scheduling\Rooms;

use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\Rooms\Pages\ManageRooms;
use App\Models\Location;
use App\Models\Room;
use BackedEnum;
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

final class RoomResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = Room::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Rooms';

    protected static ?string $slug = 'rooms';

    protected static ?int $navigationSort = 31;

    protected static function schedulingType(): string
    {
        return 'rooms';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('location_id')->required()->searchable()->options(self::locationOptions()),
            TextInput::make('name')->required()->maxLength(100),
            TextInput::make('capacity')->required()->numeric()->minValue(1)->maxValue(1000)->default(1),
            Toggle::make('active')->default(true)
                ->helperText('Retired rooms stay on historical calendar records.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->weight('medium'),
            TextColumn::make('location.name')->sortable(),
            TextColumn::make('capacity')->sortable(),
            IconColumn::make('active')->boolean(),
        ])->filters([
            SelectFilter::make('location_id')->label('Location')->options(self::locationOptions()),
            TernaryFilter::make('active')->placeholder('All rooms'),
        ])->recordActions([self::editAction()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageRooms::route('/')];
    }

    /** @return \Closure(): array<string, string> */
    private static function locationOptions(): \Closure
    {
        return fn (): array => Location::query()->where('studio_id', self::tenant()->getKey())
            ->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
