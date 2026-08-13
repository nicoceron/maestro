<?php

namespace App\TenantData\Listeners;

use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

final class RecordCurrentSessionMfaVerification
{
    public function handle(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        if (request()->hasSession()) {
            request()->session()->put('auth.mfa_verified_at', now()->timestamp);
        }
    }
}
