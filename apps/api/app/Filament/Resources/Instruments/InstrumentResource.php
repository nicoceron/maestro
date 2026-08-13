<?php

namespace App\Filament\Resources\Instruments;

use App\Filament\Resources\Instruments\Pages\ManageInstruments;
use App\Models\Instrument;
use App\Models\Studio;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use UnitEnum;

class InstrumentResource extends Resource
{
    protected static ?string $model = Instrument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMusicalNote;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Instruments';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->rules([self::uniqueNameRule()]),
            Hidden::make('normalized_name')
                ->dehydrateStateUsing(fn (Get $get): string => self::normalizeName($get('name'))),
            Toggle::make('active')
                ->default(true)
                ->helperText('Inactive instruments stay on historical records but cannot be assigned again.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('person_assignments_count')
                    ->label('Assignments')
                    ->counts('personAssignments')
                    ->sortable(),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->placeholder('All instruments')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize('update')
                    ->before(fn (Instrument $record) => Gate::authorize('update', $record))
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'normalized_name' => self::normalizeName((string) $data['name']),
                    ]),
                self::availabilityAction(),
            ])
            ->emptyStateHeading('No instruments configured')
            ->emptyStateDescription('Add the instruments people can study or teach.')
            ->defaultSort('name');
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $query = parent::getEloquentQuery();

        return $tenant instanceof Studio
            ? $query->where('studio_id', $tenant->getKey())
            : $query->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return ['index' => ManageInstruments::route('/')];
    }

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('viewAny', [Instrument::class, $tenant]);
    }

    public static function canCreate(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('create', [Instrument::class, $tenant]);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Instrument
            && $record->studio_id === self::tenantId()
            && Gate::allows('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function normalizeName(string $name): string
    {
        return Str::of($name)->trim()->squish()->lower()->toString();
    }

    public static function tenantId(): string
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Studio, 404);

        return (string) $tenant->getKey();
    }

    private static function uniqueNameRule(): Closure
    {
        return fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $query = Instrument::withTrashed()
                ->where('studio_id', self::tenantId())
                ->where('normalized_name', self::normalizeName((string) $value));

            if ($record instanceof Instrument) {
                $query->whereKeyNot($record->getKey());
            }

            if ($query->exists()) {
                $fail('An instrument with this name already exists in this studio.');
            }
        };
    }

    private static function availabilityAction(): Action
    {
        return Action::make('toggleAvailability')
            ->authorize('update')
            ->label(fn (Instrument $record): string => $record->active ? 'Deactivate' : 'Reactivate')
            ->icon(fn (Instrument $record): Heroicon => $record->active
                ? Heroicon::OutlinedArchiveBox
                : Heroicon::OutlinedArrowUturnLeft)
            ->color(fn (Instrument $record): string => $record->active ? 'danger' : 'success')
            ->requiresConfirmation(fn (Instrument $record): bool => $record->active)
            ->action(function (Instrument $record): void {
                Gate::authorize('update', $record);
                $record->update(['active' => ! $record->active]);
            });
    }
}
