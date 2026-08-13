<?php

namespace App\Filament\Resources\Households;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Filament\Resources\Households\Pages\CreateHousehold;
use App\Filament\Resources\Households\Pages\EditHousehold;
use App\Filament\Resources\Households\Pages\ListHouseholds;
use App\Filament\Resources\Households\Pages\ViewHousehold;
use App\Filament\Resources\Households\Schemas\HouseholdForm;
use App\Filament\Resources\Households\Schemas\HouseholdInfolist;
use App\Models\Household;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class HouseholdResource extends Resource
{
    protected static ?string $model = Household::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Families';

    protected static ?string $modelLabel = 'family';

    protected static ?string $pluralModelLabel = 'families';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return HouseholdForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return HouseholdInfolist::configure($schema);
    }

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
            ->recordUrl(fn (Household $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([]);
    }

    /** @return Builder<Household> */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $query = parent::getEloquentQuery()->with([
            'members.person.studentProfile',
            'guardianRelationships.guardian',
            'guardianRelationships.student',
        ]);

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
            'create' => CreateHousehold::route('/create'),
            'view' => ViewHousehold::route('/{record}'),
            'edit' => EditHousehold::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('create', [Household::class, $tenant]);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Household
            && $record->studio_id === self::tenant()->getKey()
            && Gate::allows('update', $record);
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

    public static function canViewPrivateHousehold(): bool
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $tenant instanceof Studio || ! $user instanceof User) {
            return false;
        }

        return StudioMembership::query()
            ->where('studio_id', $tenant->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->whereIn('role', [
                MembershipRole::Owner->value,
                MembershipRole::Administrator->value,
                MembershipRole::Office->value,
            ])
            ->exists();
    }
}
