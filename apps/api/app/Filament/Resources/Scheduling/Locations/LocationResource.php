<?php

namespace App\Filament\Resources\Scheduling\Locations;

use App\Enums\LocationKind;
use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\Locations\Pages\ManageLocations;
use App\Models\Location;
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

final class LocationResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = Location::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Locations';

    protected static ?string $slug = 'locations';

    protected static ?int $navigationSort = 30;

    protected static function schedulingType(): string
    {
        return 'locations';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Location')->schema([
                TextInput::make('name')->required()->maxLength(120),
                Select::make('kind')->options(LocationKind::class)->required()->default(LocationKind::Physical),
                TextInput::make('timezone')->required()->default(fn (): string => self::tenant()->timezone)
                    ->helperText('Use an IANA timezone, such as America/Bogota.'),
                TextInput::make('online_url')->url()->maxLength(2048)
                    ->helperText('Only scheduling managers can see this meeting URL.'),
                Toggle::make('active')->default(true),
            ])->columns(2),
            Section::make('Address')->schema([
                TextInput::make('address_line_1')->maxLength(160),
                TextInput::make('address_line_2')->maxLength(160),
                TextInput::make('city')->maxLength(100),
                TextInput::make('region')->maxLength(100),
                TextInput::make('postal_code')->maxLength(32),
                TextInput::make('country_code')->length(2),
            ])->columns(2)->collapsible(),
            Textarea::make('private_instructions')->maxLength(5000)->columnSpanFull()
                ->helperText('Private arrival and access details are hidden from teacher catalog responses.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable()->weight('medium'),
            TextColumn::make('kind')->badge(),
            TextColumn::make('timezone')->searchable(),
            TextColumn::make('city')->placeholder('—'),
            TextColumn::make('rooms_count')->counts('rooms')->label('Rooms'),
            IconColumn::make('active')->boolean(),
        ])->filters([TernaryFilter::make('active')->placeholder('All locations')])
            ->recordActions([self::editAction()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageLocations::route('/')];
    }
}
