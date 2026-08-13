<?php

namespace App\Filament\Resources\Scheduling\TeacherProfiles;

use App\Filament\Resources\Scheduling\Availability\Support\TeacherOptions;
use App\Filament\Resources\Scheduling\Concerns\UsesSchedulingActions;
use App\Filament\Resources\Scheduling\TeacherProfiles\Pages\ManageTeacherSchedulingProfiles;
use App\Models\StaffSchedulingProfile;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

final class TeacherSchedulingProfileResource extends Resource
{
    use UsesSchedulingActions;

    protected static ?string $model = StaffSchedulingProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Scheduling';

    protected static ?string $navigationLabel = 'Teacher constraints';

    protected static ?string $slug = 'teacher-constraints';

    protected static ?int $navigationSort = 41;

    protected static function schedulingType(): string
    {
        return 'staff-scheduling-profiles';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('staff_profile_id')->label('Teacher')->required()->searchable()
                ->options(fn (): array => TeacherOptions::for(auth()->user(), self::tenant())),
            Select::make('timezone')->required()->searchable()
                ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->default(fn (): string => self::tenant()->timezone),
            Section::make('Buffers and workload')->schema([
                TextInput::make('default_buffer_before_minutes')->label('Buffer before')->numeric()->minValue(0)->maxValue(240)->default(0)->suffix(' min'),
                TextInput::make('default_buffer_after_minutes')->label('Buffer after')->numeric()->minValue(0)->maxValue(240)->default(0)->suffix(' min'),
                TextInput::make('default_travel_buffer_minutes')->label('Fallback travel')->numeric()->minValue(0)->maxValue(240)->default(0)->suffix(' min'),
                TextInput::make('max_daily_minutes')->label('Daily teaching limit')->numeric()->minValue(1)->maxValue(1440)->suffix(' min'),
                TextInput::make('max_weekly_minutes')->label('Weekly teaching limit')->numeric()->minValue(1)->maxValue(10080)->suffix(' min'),
                Toggle::make('active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('staffProfile.person.first_name')->label('Teacher')
                ->formatStateUsing(fn (StaffSchedulingProfile $record): string => $record->staffProfile->person->displayName())
                ->searchable(['people.first_name', 'people.last_name']),
            TextColumn::make('timezone'),
            TextColumn::make('max_daily_minutes')->label('Daily limit')->suffix(' min')->placeholder('None'),
            TextColumn::make('max_weekly_minutes')->label('Weekly limit')->suffix(' min')->placeholder('None'),
            IconColumn::make('active')->boolean(),
        ])->recordActions([self::editAction()])->defaultSort('id');
    }

    public static function getPages(): array
    {
        return ['index' => ManageTeacherSchedulingProfiles::route('/')];
    }
}
