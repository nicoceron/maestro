<?php

namespace App\Actions\Fortify;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\StatefulGuard;

final class CompletePasswordReset
{
    public function __invoke(StatefulGuard $guard, mixed $user): void
    {
        event(new PasswordReset($user));
    }
}
