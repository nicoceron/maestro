<?php

namespace App\Filament\Resources\People;

use App\Enums\PersonStatus;
use App\Enums\StudentStatus;
use App\Filament\Resources\People\Pages\ListPeople;
use App\Models\Person;
use App\Models\Studio;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class PersonResource extends Resource
{
    protected static ?string $model = Person::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Directory';

    protected static ?string $modelLabel = 'person';

    protected static ?string $pluralModelLabel = 'people';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label('Name')
                    ->state(fn (Person $record): string => $record->displayName())
                    ->searchable(['first_name', 'last_name', 'preferred_name'])
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderBy('last_name', $direction)
                        ->orderBy('first_name', $direction)),
                TextColumn::make('studentProfile.status')
                    ->label('Student status')
                    ->badge()
                    ->placeholder('Not a student')
                    ->formatStateUsing(fn (StudentStatus|string|null $state): string => $state instanceof StudentStatus
                        ? Str::headline($state->value)
                        : Str::headline((string) $state))
                    ->color(fn (StudentStatus|string|null $state): string => match ($state instanceof StudentStatus ? $state : StudentStatus::tryFrom((string) $state)) {
                        StudentStatus::Active => 'success',
                        StudentStatus::Trial, StudentStatus::Lead => 'info',
                        StudentStatus::Waiting, StudentStatus::Paused => 'warning',
                        StudentStatus::Former => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('email')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('phone')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PersonStatus|string $state): string => Str::headline($state instanceof PersonStatus ? $state->value : $state))
                    ->color(fn (PersonStatus|string $state): string => match ($state instanceof PersonStatus ? $state : PersonStatus::tryFrom($state)) {
                        PersonStatus::Active => 'success',
                        PersonStatus::Inactive => 'warning',
                        PersonStatus::Archived => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('last_name')
            ->filters([
                SelectFilter::make('status')->options(self::personStatusLabels()),
                SelectFilter::make('student_status')
                    ->label('Student status')
                    ->options(self::studentStatusLabels())
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, string $status): Builder => $query->whereHas(
                            'studentProfile',
                            fn (Builder $profile): Builder => $profile->where('status', $status),
                        ),
                    )),
            ])
            ->recordActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Studio) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('studio_id', $tenant->getKey())
            ->with('studentProfile');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeople::route('/'),
        ];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function tenant(): Studio
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Studio, 404);

        return $tenant;
    }

    public static function user(): User
    {
        $user = Filament::auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /** @return array<string, string> */
    public static function studentStatusLabels(): array
    {
        return collect(StudentStatus::cases())
            ->mapWithKeys(fn (StudentStatus $status): array => [$status->value => Str::headline($status->value)])
            ->all();
    }

    /** @return array<string, string> */
    private static function personStatusLabels(): array
    {
        return collect(PersonStatus::cases())
            ->mapWithKeys(fn (PersonStatus $status): array => [$status->value => Str::headline($status->value)])
            ->all();
    }
}
