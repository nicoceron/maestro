<?php

namespace App\Filament\Resources\SupportAccessGrants;

use App\Filament\Resources\SupportAccessGrants\Pages\ListSupportAccessGrants;
use App\Models\Studio;
use App\Models\User;
use App\SupportAccess\Actions\ApproveSupportAccess;
use App\SupportAccess\Actions\RejectSupportAccess;
use App\SupportAccess\Actions\RevokeSupportAccess;
use App\SupportAccess\GrantStatus;
use App\SupportAccess\Models\SupportAccessGrant;
use App\SupportAccess\SupportAccessException;
use App\SupportAccess\SupportAccessPolicy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class SupportAccessGrantResource extends Resource
{
    protected static ?string $model = SupportAccessGrant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Support access';

    protected static ?int $navigationSort = 91;

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('requester.name')->label('Support operator')->searchable(),
            TextColumn::make('reason')->limit(80)->wrap(),
            TextColumn::make('scopes')->badge()->separator(','),
            TextColumn::make('status')->badge(),
            TextColumn::make('starts_at')->dateTime(),
            TextColumn::make('expires_at')->dateTime()->color(fn (SupportAccessGrant $record): string => $record->expires_at->isPast() ? 'danger' : 'gray'),
        ])->recordActions([
            Action::make('approve')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (SupportAccessGrant $record): bool => $record->status === GrantStatus::Requested)
                ->requiresConfirmation()->modalDescription('This creates visible, time-bounded, read-only support access. Every support view is audited.')
                ->action(function (SupportAccessGrant $record): void {
                    self::assertRecentAuthentication();
                    app(ApproveSupportAccess::class)->handle(Filament::auth()->user(), $record, $record->version);
                }),
            Action::make('reject')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (SupportAccessGrant $record): bool => $record->status === GrantStatus::Requested)
                ->schema([Textarea::make('reason')->required()->minLength(4)->maxLength(500)])
                ->action(function (SupportAccessGrant $record, array $data): void {
                    self::assertRecentAuthentication();
                    app(RejectSupportAccess::class)->handle(Filament::auth()->user(), $record, $record->version, $data['reason']);
                }),
            Action::make('revoke')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (SupportAccessGrant $record): bool => $record->status === GrantStatus::Approved)
                ->schema([Textarea::make('reason')->required()->minLength(4)->maxLength(500)])
                ->requiresConfirmation()->action(function (SupportAccessGrant $record, array $data): void {
                    self::assertRecentAuthentication();
                    app(RevokeSupportAccess::class)->handle(Filament::auth()->user(), $record, $record->version, $data['reason']);
                }),
        ])->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No support access requests')
            ->emptyStateDescription('Support cannot enter this studio until an owner or administrator explicitly approves a request.');
    }

    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            ? parent::getEloquentQuery()->with(['requester', 'approver'])->where('studio_id', $tenant->getKey())
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();

        return $tenant instanceof Studio && $user instanceof User
            && app(SupportAccessPolicy::class)->manageStudio($user, $tenant);
    }

    public static function canCreate(): bool
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
        return ['index' => ListSupportAccessGrants::route('/')];
    }

    private static function assertRecentAuthentication(): void
    {
        $confirmedAt = session('auth.password_confirmed_at', 0);
        $mfaAt = session('support_access.mfa_verified_at', 0);
        $maximumAge = (int) config('support-access.recent_auth_seconds', 600);
        if (! is_int($confirmedAt) || now()->timestamp - $confirmedAt > $maximumAge) {
            throw new SupportAccessException('support_recent_auth_required', 'Confirm your password again before changing support access.', 423);
        }
        if (! is_int($mfaAt) || now()->timestamp - $mfaAt > $maximumAge) {
            throw new SupportAccessException('support_current_session_mfa_required', 'Complete a current-session MFA challenge before changing support access.', 423);
        }
    }
}
