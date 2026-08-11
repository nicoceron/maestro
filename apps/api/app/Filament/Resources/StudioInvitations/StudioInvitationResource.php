<?php

namespace App\Filament\Resources\StudioInvitations;

use App\Enums\MembershipRole;
use App\Filament\Resources\StudioInvitations\Pages\ListStudioInvitations;
use App\Filament\StudioInvitations\StudioInvitationManager;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use UnitEnum;

class StudioInvitationResource extends Resource
{
    protected static ?string $model = StudioInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static ?string $navigationLabel = 'Team invitations';

    protected static ?string $modelLabel = 'team invitation';

    protected static ?string $pluralModelLabel = 'team invitations';

    protected static string|UnitEnum|null $navigationGroup = 'Studio';

    protected static ?string $recordTitleAttribute = 'email_normalized';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email_normalized')
                    ->label('Email')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (MembershipRole|string $state): string => Str::headline(
                        $state instanceof MembershipRole ? $state->value : $state,
                    )),
                TextColumn::make('status')
                    ->state(fn (StudioInvitation $record): string => $record->status())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'accepted' => 'success',
                        'expired', 'superseded' => 'gray',
                        'revoked' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('latestDelivery.status')
                    ->label('Delivery')
                    ->badge()
                    ->placeholder('Not queued'),
                TextColumn::make('last_sent_at')
                    ->label('Last sent')
                    ->since()
                    ->placeholder('Pending')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Invited')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')->options(self::roleLabels()),
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'expired' => 'Expired',
                        'accepted' => 'Accepted',
                        'revoked' => 'Revoked',
                        'superseded' => 'Superseded',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'accepted' => $query->whereNotNull('accepted_at'),
                            'revoked' => $query->whereNull('accepted_at')->whereNotNull('revoked_at'),
                            'superseded' => $query->whereNull('accepted_at')->whereNull('revoked_at')->whereNotNull('superseded_at'),
                            'expired' => $query->whereNull('accepted_at')->whereNull('revoked_at')->whereNull('superseded_at')->where('expires_at', '<=', now()),
                            'pending' => $query->whereNull('accepted_at')->whereNull('revoked_at')->whereNull('superseded_at')->where('expires_at', '>', now()),
                            default => $query,
                        };
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('resend')
                    ->label(fn (StudioInvitation $record): string => $record->status() === 'expired' ? 'Renew' : 'Resend')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->visible(fn (StudioInvitation $record): bool => in_array($record->status(), ['pending', 'expired'], true)
                        && Gate::allows('resend', $record))
                    ->disabled(fn (StudioInvitation $record): bool => ! $record->canBeResent())
                    ->schema([self::passwordInput()])
                    ->action(function (Action $action, StudioInvitation $record, array $data, $livewire): void {
                        try {
                            $studio = self::tenant();
                            app(StudioInvitationManager::class)->resend(
                                $studio,
                                $record,
                                self::user(),
                                (string) ($data['current_password'] ?? ''),
                            );
                            $livewire->resetTable();
                        } catch (TooManyRequestsHttpException $exception) {
                            self::notifyRateLimit($exception);
                            $action->halt();

                            return;
                        }

                        Notification::make()->success()->title('Invitation resend queued')->send();
                    }),
                Action::make('revoke')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->requiresConfirmation()
                    ->visible(fn (StudioInvitation $record): bool => $record->isPending()
                        && Gate::allows('revoke', $record))
                    ->schema([self::passwordInput()])
                    ->action(function (StudioInvitation $record, array $data, $livewire): void {
                        app(StudioInvitationManager::class)->revoke(
                            self::tenant(),
                            $record,
                            self::user(),
                            (string) ($data['current_password'] ?? ''),
                        );
                        $livewire->resetTable();
                        Notification::make()->success()->title('Invitation revoked')->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    /** @return Builder<StudioInvitation> */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();
        $query = parent::getEloquentQuery()->with(['latestDelivery', 'studio']);

        if (! $tenant instanceof Studio) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('studio_id', $tenant->getKey());
    }

    /** @return array<string, mixed> */
    public static function getPages(): array
    {
        return [
            'index' => ListStudioInvitations::route('/'),
        ];
    }

    public static function canViewAny(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('viewAny', [StudioInvitation::class, $tenant]);
    }

    public static function canCreate(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio
            && Gate::allows('create', [StudioInvitation::class, $tenant]);
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
    public static function invitableRoleLabels(): array
    {
        $tenant = Filament::getTenant();
        $user = Filament::auth()->user();

        if (! $tenant instanceof Studio || ! $user instanceof User) {
            return [];
        }

        $policy = Gate::getPolicyFor(StudioInvitation::class);

        return collect($policy->invitableRoles($user, $tenant))
            ->mapWithKeys(fn (string $role): array => [$role => Str::headline($role)])
            ->all();
    }

    public static function notifyRateLimit(TooManyRequestsHttpException $exception): void
    {
        $retryAfter = (int) ($exception->getHeaders()['Retry-After'] ?? 0);
        $body = $retryAfter > 0
            ? "Try again in {$retryAfter} seconds."
            : 'Try again later.';

        Notification::make()
            ->danger()
            ->title('Invitation limit reached')
            ->body($body)
            ->send();
    }

    public static function passwordInput(): TextInput
    {
        return TextInput::make('current_password')
            ->label('Current password')
            ->password()
            ->revealable()
            ->visible(fn (): bool => app(StudioInvitationManager::class)->requiresIdentityConfirmation())
            ->required(fn (): bool => app(StudioInvitationManager::class)->requiresIdentityConfirmation())
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    app(StudioInvitationManager::class)->confirmIdentity(
                        self::user(),
                        (string) $value,
                    );
                } catch (ValidationException $exception) {
                    $fail($exception->errors()['current_password'][0] ?? 'Identity confirmation failed.');
                }
            })
            ->autocomplete('current-password');
    }

    /** @return array<string, string> */
    private static function roleLabels(): array
    {
        return collect(MembershipRole::cases())
            ->reject(fn (MembershipRole $role): bool => $role === MembershipRole::Owner)
            ->mapWithKeys(fn (MembershipRole $role): array => [$role->value => Str::headline($role->value)])
            ->all();
    }
}
