<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NormalizeAuthenticationEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->input('email');

        if (is_string($email)) {
            $request->merge(['email' => User::normalizeEmail($email)]);
        }

        return $next($request);
    }
}
