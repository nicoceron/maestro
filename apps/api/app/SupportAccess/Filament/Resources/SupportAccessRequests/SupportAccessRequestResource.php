<?php

namespace App\SupportAccess\Filament\Resources\SupportAccessRequests;

use App\Models\User;
use App\SupportAccess\Filament\Resources\SupportAccessRequests\Pages\ListSupportAccessRequests;
use App\SupportAccess\Models\SupportAccessGrant;
use App\SupportAccess\SupportAccessPolicy;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class SupportAccessRequestResource extends Resource
{
    protected static ?string $model = SupportAccessGrant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'My access requests';

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('studio.name')->label('Studio')->searchable(),
            TextColumn::make('reason')->limit(100)->wrap(),
            TextColumn::make('scopes')->badge()->separator(','),
            TextColumn::make('status')->badge(),
            TextColumn::make('starts_at')->dateTime(),
            TextColumn::make('expires_at')->dateTime(),
        ])->defaultSort('created_at', 'desc')
            ->recordUrl(null)
            ->emptyStateHeading('No support access requests')
            ->emptyStateDescription('Use the support API to request a narrowly scoped, studio-approved session. Session tokens are shown exactly once and never stored in this panel.');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            ? parent::getEloquentQuery()->with('studio')->where('requested_by_user_id', $user->getAuthIdentifier())
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(SupportAccessPolicy::class)->isOperator($user);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListSupportAccessRequests::route('/')];
    }
}
