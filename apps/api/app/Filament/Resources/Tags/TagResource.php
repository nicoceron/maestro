<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Models\Studio;
use App\Models\Tag;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use UnitEnum;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 31;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(80)
                ->rules([self::uniqueNameRule()]),
            Hidden::make('normalized_name')
                ->dehydrateStateUsing(fn (Get $get): string => self::normalizeName($get('name'))),
            ColorPicker::make('color')
                ->label('Label color')
                ->helperText('Optional visual aid; the tag name always remains visible.'),
            Toggle::make('active')
                ->default(true)
                ->helperText('Inactive tags remain on historical records but cannot be assigned again.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                ColorColumn::make('color')->label('Color'),
                TextColumn::make('person_assignments_count')
                    ->label('People')
                    ->counts('personAssignments')
                    ->sortable(),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([TernaryFilter::make('active')])
            ->recordActions([
                EditAction::make()
                    ->authorize('update')
                    ->before(fn (Tag $record) => Gate::authorize('update', $record))
                    ->mutateDataUsing(fn (array $data): array => [
                        ...$data,
                        'normalized_name' => self::normalizeName((string) $data['name']),
                    ]),
                Action::make('toggleAvailability')
                    ->authorize('update')
                    ->label(fn (Tag $record): string => $record->active ? 'Deactivate' : 'Reactivate')
                    ->icon(fn (Tag $record): Heroicon => $record->active
                        ? Heroicon::OutlinedArchiveBox
                        : Heroicon::OutlinedArrowUturnLeft)
                    ->color(fn (Tag $record): string => $record->active ? 'danger' : 'success')
                    ->requiresConfirmation(fn (Tag $record): bool => $record->active)
                    ->action(function (Tag $record): void {
                        Gate::authorize('update', $record);
                        $record->update(['active' => ! $record->active]);
                    }),
            ])
            ->emptyStateHeading('No tags configured')
            ->emptyStateDescription('Use tags for meaningful segments such as auditions, ensembles, or follow-up groups.')
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
        return ['index' => ManageTags::route('/')];
    }

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('viewAny', [Tag::class, $tenant]);
    }

    public static function canCreate(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('create', [Tag::class, $tenant]);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Tag
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
            $query = Tag::withTrashed()
                ->where('studio_id', self::tenantId())
                ->where('normalized_name', self::normalizeName((string) $value));

            if ($record instanceof Tag) {
                $query->whereKeyNot($record->getKey());
            }

            if ($query->exists()) {
                $fail('A tag with this name already exists in this studio.');
            }
        };
    }
}
