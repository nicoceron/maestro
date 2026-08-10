<?php

namespace App\Support\Auth;

use App\Models\User;

final class LoginRateLimitKey
{
    public function for(string $email, string $ipAddress): string
    {
        $normalizedEmail = User::normalizeEmail($email);
        $digest = hash_hmac('sha256', $normalizedEmail, (string) config('app.key'));

        return 'login|'.$digest.'|'.$ipAddress;
    }
}
