<?php

namespace App\Filament\StudioInvitations;

use App\Actions\Invitations\CreateStudioInvitation;
use App\Actions\Invitations\ResendStudioInvitation;
use App\Actions\Invitations\RevokeStudioInvitation;
use App\Enums\MembershipRole;
use App\Http\Middleware\ApplyNamedRateLimiter;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\User;
use App\Support\Auth\SensitiveRateLimitKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class StudioInvitationManager
{
    public function __construct(
        private readonly CreateStudioInvitation $createInvitation,
        private readonly ResendStudioInvitation $resendInvitation,
        private readonly RevokeStudioInvitation $revokeInvitation,
        private readonly ApplyNamedRateLimiter $rateLimiter,
        private readonly SensitiveRateLimitKey $sensitiveKeys,
    ) {}

    public function create(
        Studio $studio,
        User $actor,
        string $email,
        MembershipRole $role,
        string $password,
    ): StudioInvitation {
        $this->confirmIdentity($actor, $password);
        Gate::authorize('create', [StudioInvitation::class, $studio]);
        Gate::authorize('invite', [StudioInvitation::class, $studio, $role]);

        return $this->rateLimited(
            'invitation-create',
            studio: $studio,
            callback: fn (): StudioInvitation => $this->createInvitation->handle(
                $studio,
                $actor,
                $email,
                $role,
            ),
        );
    }

    public function resend(
        Studio $studio,
        StudioInvitation $invitation,
        User $actor,
        string $password,
    ): StudioInvitation {
        abort_unless($invitation->studio_id === $studio->getKey(), 404);
        $this->confirmIdentity($actor, $password);
        Gate::authorize('resend', $invitation);

        return $this->rateLimited(
            'invitation-resend',
            studio: $studio,
            invitation: $invitation,
            callback: fn (): StudioInvitation => $this->resendInvitation->handle($invitation, $actor),
        );
    }

    public function revoke(
        Studio $studio,
        StudioInvitation $invitation,
        User $actor,
        string $password,
    ): void {
        abort_unless($invitation->studio_id === $studio->getKey(), 404);
        $this->confirmIdentity($actor, $password);
        Gate::authorize('revoke', $invitation);
        $this->revokeInvitation->handle($invitation, $actor);
    }

    public function requiresIdentityConfirmation(): bool
    {
        $request = request();

        if (! $request->hasSession()) {
            $request->setLaravelSession(app('session')->driver());
        }

        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt <= 0
            || now()->timestamp - $confirmedAt > (int) config('auth.password_timeout', 600);
    }

    public function confirmIdentity(User $actor, string $password): void
    {
        if (! $this->requiresIdentityConfirmation()) {
            return;
        }

        $request = request();
        $accountKey = $this->sensitiveKeys->for(
            'filament-identity-confirmation-account',
            $actor->getAuthIdentifier(),
        );
        $ipKey = $this->sensitiveKeys->for(
            'filament-identity-confirmation-ip',
            (string) $request->ip(),
        );

        if (RateLimiter::tooManyAttempts($accountKey, 5)
            || RateLimiter::tooManyAttempts($ipKey, 30)) {
            throw ValidationException::withMessages([
                'current_password' => ['Too many confirmation attempts. Try again later.'],
            ]);
        }

        if (! is_string($actor->password)
            || $actor->password === ''
            || ! Hash::check($password, $actor->password)) {
            RateLimiter::hit($accountKey, 60);
            RateLimiter::hit($ipKey, 60);

            throw ValidationException::withMessages([
                'current_password' => [
                    blank($actor->password)
                        ? 'Confirm with a passkey, then retry this action.'
                        : 'The password is incorrect.',
                ],
            ]);
        }

        RateLimiter::clear($accountKey);
        $request->session()->put('auth.password_confirmed_at', now()->timestamp);
    }

    private function rateLimited(
        string $limiter,
        Studio $studio,
        Closure $callback,
        ?StudioInvitation $invitation = null,
    ): mixed {
        $request = clone request();
        $request->attributes->set('invitation_rate_limit_studio', $studio);
        $request->attributes->set('invitation_rate_limit_invitation', $invitation);
        $result = null;

        $this->rateLimiter->handle(
            $request,
            function (Request $request) use ($callback, &$result) {
                $result = $callback();

                return response()->noContent();
            },
            $limiter,
        );

        return $result;
    }
}
