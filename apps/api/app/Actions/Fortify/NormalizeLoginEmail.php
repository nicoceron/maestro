<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;

final class NormalizeLoginEmail
{
    public function handle(Request $request, Closure $next): mixed
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge(['email' => User::normalizeEmail($email)]);
        }

        return $next($request);
    }
}
