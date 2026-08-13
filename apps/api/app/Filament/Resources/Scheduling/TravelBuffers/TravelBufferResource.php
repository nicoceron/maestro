<?php

namespace App\Filament\Resources\Scheduling\TravelBuffers;

use App\Filament\Resources\Scheduling\Availability\Support\TeacherOptions;
use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\TravelBuffers\Pages\ManageTravelBuffers;
use App\Models\Location;
use App\Models\StaffTravelBuffer;
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
use Filament\Tables\Table;
use UnitEnum;

final class TravelBufferResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = StaffTravelBuffer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Travel times';

    protected static ?string $slug = 'travel-times';

    protected static ?int $navigationSort = 43;

    protected static function schedulingType(): string
    {
        return 'travel-buffers';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('staff_profile_id')->label('Teacher')->required()->searchable()
                ->options(fn (): array => TeacherOptions::for(auth()->user(), self::tenant())),
            Select::make('from_location_id')->label('From')->required()->searchable()->options(self::locationOptions()),
            Select::make('to_location_id')->label('To')->required()->searchable()->options(self::locationOptions()),
            TextInput::make('minutes')->required()->numeric()->minValue(0)->maxValue(240)->suffix(' min'),
            Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('staffProfile.person.first_name')->label('Teacher')
                ->formatStateUsing(fn (StaffTravelBuffer $record): string => $record->staffProfile->person->displayName()),
            TextColumn::make('fromLocation.name')->label('From'),
            TextColumn::make('toLocation.name')->label('To'),
            TextColumn::make('minutes')->suffix(' min')->sortable(),
            IconColumn::make('active')->boolean(),
        ])->filters([
            SelectFilter::make('staff_profile_id')->label('Teacher')
                ->options(fn (): array => TeacherOptions::for(auth()->user(), self::tenant())),
        ])->recordActions([self::editAction()])->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTravelBuffers::route('/')];
    }

    /** @return \Closure(): array<string, string> */
    private static function locationOptions(): \Closure
    {
        return fn (): array => Location::query()->where('studio_id', self::tenant()->getKey())
            ->where('active', true)->orderBy('name')->pluck('name', 'id')->all();
    }
}
