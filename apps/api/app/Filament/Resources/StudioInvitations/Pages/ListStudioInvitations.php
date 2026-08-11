<?php

namespace App\Filament\Resources\StudioInvitations\Pages;

use App\Enums\MembershipRole;
use App\Filament\Resources\StudioInvitations\StudioInvitationResource;
use App\Filament\StudioInvitations\StudioInvitationManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ListStudioInvitations extends ListRecords
{
    protected static string $resource = StudioInvitationResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite team member')
                ->icon('heroicon-o-user-plus')
                ->visible(StudioInvitationResource::canCreate())
                ->schema([
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(254)
                        ->autocomplete('email'),
                    Select::make('role')
                        ->options(StudioInvitationResource::invitableRoleLabels())
                        ->required()
                        ->native(false),
                    StudioInvitationResource::passwordInput(),
                ])
                ->action(function (Action $action, array $data): void {
                    try {
                        $role = MembershipRole::tryFrom((string) $data['role']);

                        if ($role === null) {
                            throw ValidationException::withMessages([
                                'role' => 'Choose an available invitation role.',
                            ]);
                        }

                        app(StudioInvitationManager::class)->create(
                            StudioInvitationResource::tenant(),
                            StudioInvitationResource::user(),
                            (string) $data['email'],
                            $role,
                            (string) ($data['current_password'] ?? ''),
                        );
                        $this->resetTable();
                    } catch (TooManyRequestsHttpException $exception) {
                        StudioInvitationResource::notifyRateLimit($exception);
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title('Invitation queued')->send();
                }),
        ];
    }
}
