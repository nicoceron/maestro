<?php

namespace App\DataPortability\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class CrmDataPortabilityStepUp
{
    public function assert(User $user, Session $session): void
    {
        $window = (int) config('data-portability.step_up_seconds', 600);
        $passwordAt = $session->get('auth.password_confirmed_at');
        $mfaAt = $session->get('auth.mfa_verified_at');

        if (! $this->isFresh($passwordAt, $window)) {
            throw new HttpException(423, 'RECENT_CONFIRMATION_REQUIRED');
        }

        if (! $user->hasEnabledTwoFactorAuthentication() || ! $this->isFresh($mfaAt, $window)) {
            throw new HttpException(423, 'MFA_REQUIRED');
        }
    }

    private function isFresh(mixed $timestamp, int $window): bool
    {
        if (! is_int($timestamp) && ! ctype_digit((string) $timestamp)) {
            return false;
        }

        $age = now()->timestamp - (int) $timestamp;

        return $age >= 0 && $age <= $window;
    }
}
