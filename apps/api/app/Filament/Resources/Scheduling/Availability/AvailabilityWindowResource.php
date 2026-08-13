<?php

namespace App\Filament\Resources\Scheduling\Availability;

use App\Enums\AvailabilityEnforcement;
use App\Filament\Resources\Scheduling\Availability\Pages\ManageAvailabilityWindows;
use App\Filament\Resources\Scheduling\Availability\Support\TeacherOptions;
use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Models\StaffAvailabilityWindow;
use App\Models\Studio;
use App\Support\Scheduling\SchedulingAccess;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

final class AvailabilityWindowResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = StaffAvailabilityWindow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Teacher availability';

    protected static ?string $slug = 'teacher-availability';

    protected static ?int $navigationSort = 40;

    protected static function schedulingType(): string
    {
        return 'availability-windows';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('staff_profile_id')->label('Teacher')->required()->searchable()
                ->options(fn (): array => TeacherOptions::for(auth()->user(), self::tenant())),
            Select::make('weekday')->required()->options([
                1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
            ]),
            TimePicker::make('start_time')->seconds(false)->required(),
            TimePicker::make('end_time')->seconds(false)->required()->after('start_time'),
            Select::make('enforcement')->options(AvailabilityEnforcement::class)->default(AvailabilityEnforcement::Hard)
                ->visible(fn (): bool => self::canManage())
                ->helperText('Hard availability blocks booking; soft availability produces a manager-only warning.'),
            Select::make('timezone')->required()->searchable()
                ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->default(fn (): string => self::tenant()->timezone),
            Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('staffProfile.person.first_name')->label('Teacher')
                ->formatStateUsing(fn (StaffAvailabilityWindow $record): string => $record->staffProfile->person->displayName())
                ->searchable(['people.first_name', 'people.last_name']),
            TextColumn::make('weekday')->formatStateUsing(fn (int $state): string => [
                1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
            ][$state]),
            TextColumn::make('start_time')->time('g:i A'),
            TextColumn::make('end_time')->time('g:i A'),
            TextColumn::make('timezone')->toggleable(),
            TextColumn::make('enforcement')->badge()->visible(fn (): bool => self::canManage()),
            IconColumn::make('active')->boolean(),
        ])->filters([
            SelectFilter::make('staff_profile_id')->label('Teacher')
                ->options(fn (): array => TeacherOptions::for(auth()->user(), self::tenant())),
            TernaryFilter::make('active')->placeholder('All windows'),
        ])->recordActions([self::editAction()])->defaultSort('weekday');
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $query = parent::getEloquentQuery();

        if (! $tenant instanceof Studio || auth()->user() === null) {
            return $query->whereRaw('1 = 0');
        }

        return app(SchedulingAccess::class)->scopeVisible(
            $query->where('studio_id', $tenant->getKey())->with('staffProfile.person'),
            auth()->user(),
            $tenant,
            StaffAvailabilityWindow::class,
        );
    }

    public static function getPages(): array
    {
        return ['index' => ManageAvailabilityWindows::route('/')];
    }

    private static function canManage(): bool
    {
        return auth()->user() !== null
            && app(SchedulingAccess::class)->canManage(auth()->user(), self::tenant());
    }
}
