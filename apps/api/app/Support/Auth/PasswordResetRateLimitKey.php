<?php

namespace App\Support\Auth;

use App\Models\User;

final class PasswordResetRateLimitKey
{
    public function for(string $email, string $ipAddress): string
    {
        $digest = hash_hmac(
            'sha256',
            User::normalizeEmail($email),
            (string) config('app.key'),
        );

        return 'password-reset|'.$digest.'|'.$ipAddress;
    }
}
