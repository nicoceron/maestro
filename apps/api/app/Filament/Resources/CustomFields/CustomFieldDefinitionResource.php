<?php

namespace App\Filament\Resources\CustomFields;

use App\Enums\CustomFieldAppliesTo;
use App\Enums\CustomFieldType;
use App\Filament\Resources\CustomFields\Pages\ManageCustomFieldDefinitions;
use App\Models\CustomFieldDefinition;
use App\Models\Studio;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class CustomFieldDefinitionResource extends Resource
{
    protected static ?string $model = CustomFieldDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?string $navigationLabel = 'Custom fields';

    protected static ?string $modelLabel = 'custom field';

    protected static ?string $pluralModelLabel = 'custom fields';

    protected static ?string $slug = 'custom-fields';

    protected static ?int $navigationSort = 32;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(100),
            TextInput::make('key')
                ->required()
                ->maxLength(80)
                ->regex('/^[a-z][a-z0-9_]{0,79}$/')
                ->helperText('Stable lowercase key using letters, numbers, and underscores.')
                ->rules([self::uniqueKeyRule()])
                ->disabled(fn (?CustomFieldDefinition $record): bool => $record !== null)
                ->dehydrated(),
            Select::make('type')
                ->options(self::enumOptions(CustomFieldType::cases()))
                ->required()
                ->native(false)
                ->live()
                ->disabled(fn (?CustomFieldDefinition $record): bool => $record?->values()->exists() ?? false)
                ->dehydrated(),
            Select::make('applies_to')
                ->label('Applies to')
                ->options(self::enumOptions(CustomFieldAppliesTo::cases()))
                ->default(CustomFieldAppliesTo::Person->value)
                ->required()
                ->native(false)
                ->disabled(fn (?CustomFieldDefinition $record): bool => $record?->values()->exists() ?? false)
                ->dehydrated(),
            TagsInput::make('options')
                ->helperText(fn (?CustomFieldDefinition $record): string => $record?->values()->exists()
                    ? 'Options are locked because people already have values. Retire this field and create a replacement to change its shape.'
                    : 'Options are stored exactly as shown and become immutable after the first value is saved.')
                ->required(fn (Get $get): bool => in_array($get('type'), [
                    CustomFieldType::Select->value,
                    CustomFieldType::MultiSelect->value,
                ], true))
                ->visible(fn (Get $get): bool => in_array($get('type'), [
                    CustomFieldType::Select->value,
                    CustomFieldType::MultiSelect->value,
                ], true))
                ->disabled(fn (?CustomFieldDefinition $record): bool => $record?->values()->exists() ?? false)
                ->dehydrated()
                ->rules([self::optionsRule()]),
            Toggle::make('required')
                ->helperText('Required applies only while this field is active and relevant to the person type.'),
            Toggle::make('active')
                ->default(true)
                ->helperText('Inactive fields retain existing values but disappear from person forms.'),
            TextInput::make('sort_order')
                ->label('Display order')
                ->integer()
                ->minValue(0)
                ->maxValue(65535)
                ->default(0)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('medium'),
                TextColumn::make('key')->searchable()->copyable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (CustomFieldType|string $state): string => Str::headline(
                        $state instanceof CustomFieldType ? $state->value : $state,
                    )),
                TextColumn::make('applies_to')
                    ->label('Applies to')
                    ->badge()
                    ->formatStateUsing(fn (CustomFieldAppliesTo|string $state): string => Str::headline(
                        $state instanceof CustomFieldAppliesTo ? $state->value : $state,
                    )),
                TextColumn::make('values_count')->label('Values')->counts('values')->sortable(),
                IconColumn::make('required')->boolean(),
                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('applies_to')->options(self::enumOptions(CustomFieldAppliesTo::cases())),
                TernaryFilter::make('active'),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize('update')
                    ->before(function (CustomFieldDefinition $record, array $data): void {
                        Gate::authorize('update', $record);
                        self::assertSafeChange($record, $data);
                    }),
                Action::make('toggleAvailability')
                    ->authorize('update')
                    ->label(fn (CustomFieldDefinition $record): string => $record->active ? 'Deactivate' : 'Reactivate')
                    ->icon(fn (CustomFieldDefinition $record): Heroicon => $record->active
                        ? Heroicon::OutlinedArchiveBox
                        : Heroicon::OutlinedArrowUturnLeft)
                    ->color(fn (CustomFieldDefinition $record): string => $record->active ? 'danger' : 'success')
                    ->requiresConfirmation(fn (CustomFieldDefinition $record): bool => $record->active)
                    ->action(function (CustomFieldDefinition $record): void {
                        Gate::authorize('update', $record);
                        $record->update(['active' => ! $record->active]);
                    }),
            ])
            ->emptyStateHeading('No custom fields configured')
            ->emptyStateDescription('Add fields only when the standard profile does not capture a studio-specific need.')
            ->defaultSort('sort_order');
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
        return ['index' => ManageCustomFieldDefinitions::route('/')];
    }

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('viewAny', [CustomFieldDefinition::class, $tenant]);
    }

    public static function canCreate(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('create', [CustomFieldDefinition::class, $tenant]);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof CustomFieldDefinition
            && $record->studio_id === self::tenantId()
            && Gate::allows('update', $record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function tenantId(): string
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Studio, 404);

        return (string) $tenant->getKey();
    }

    public static function normalizeKey(string $key): string
    {
        return Str::of($key)->trim()->lower()->replace('-', '_')->toString();
    }

    /** @param array<string, mixed> $data */
    public static function assertSafeChange(CustomFieldDefinition $record, array $data): void
    {
        if (! $record->values()->exists()) {
            return;
        }

        $type = CustomFieldType::tryFrom((string) ($data['type'] ?? $record->type->value));

        if ($type !== $record->type) {
            throw ValidationException::withMessages([
                'type' => 'The field type cannot change after values have been saved.',
            ]);
        }

        if (($data['applies_to'] ?? $record->applies_to->value) !== $record->applies_to->value) {
            throw ValidationException::withMessages([
                'applies_to' => 'The audience cannot change after values have been saved.',
            ]);
        }

        if (($data['options'] ?? $record->options) !== $record->options) {
            throw ValidationException::withMessages([
                'options' => 'Options cannot change after values have been saved. Retire this field and create a replacement.',
            ]);
        }
    }

    private static function uniqueKeyRule(): Closure
    {
        return fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $query = CustomFieldDefinition::withTrashed()
                ->where('studio_id', self::tenantId())
                ->where('key', self::normalizeKey((string) $value));

            if ($record instanceof CustomFieldDefinition) {
                $query->whereKeyNot($record->getKey());
            }

            if ($query->exists()) {
                $fail('A custom field with this key already exists in this studio.');
            }
        };
    }

    private static function optionsRule(): Closure
    {
        return fn (?Model $record): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            if (count($value) > 100) {
                $fail('A custom field can have at most 100 options.');

                return;
            }

            foreach ($value as $option) {
                if (! is_string($option)
                    || mb_strlen(trim($option)) < 1
                    || mb_strlen($option) > 100) {
                    $fail('Each option must contain between 1 and 100 characters.');

                    return;
                }
            }

            $normalized = array_map(
                fn (mixed $option): string => Str::of((string) $option)->trim()->squish()->lower()->toString(),
                $value,
            );

            if (in_array('', $normalized, true) || count($normalized) !== count(array_unique($normalized))) {
                $fail('Options must be non-empty and unique, ignoring capitalization.');

                return;
            }

        };
    }

    /** @param list<BackedEnum> $cases @return array<string, string> */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)->mapWithKeys(fn (BackedEnum $case): array => [
            (string) $case->value => Str::headline((string) $case->value),
        ])->all();
    }
}
