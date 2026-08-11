<?php

namespace App\Filament\Resources\Households;

use App\Filament\Resources\Households\Pages\ListHouseholds;
use App\Models\Household;
use App\Models\Studio;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HouseholdResource extends Resource
{
    protected static ?string $model = Household::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Families';

    protected static ?string $modelLabel = 'family';

    protected static ?string $pluralModelLabel = 'families';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Household')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('members.person.first_name')
                    ->label('People')
                    ->formatStateUsing(function (Household $record): string {
                        return $record->members
                            ->map(fn ($member): string => $member->person->displayName())
                            ->join(', ');
                    })
                    ->wrap()
                    ->limit(80),
                TextColumn::make('members_count')
                    ->label('Members')
                    ->counts('members')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->recordActions([])
            ->toolbarActions([]);
    }

    /** @return Builder<Household> */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $query = parent::getEloquentQuery()->with('members.person');

        if (! $tenant instanceof Studio) {
            return $query->whereRaw('1 = 0');
        }

        // Keep an explicit predicate even though Filament also registers its
        // tenant scope. This makes custom table queries default-deny in tests
        // and if resource discovery order changes.
        return $query->where('studio_id', $tenant->getKey());
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListHouseholds::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        // Household creation is an aggregate workflow. It will be enabled in
        // Filament with the same guarded action used by the API, never as an
        // empty model form.
        return false;
    }
}
