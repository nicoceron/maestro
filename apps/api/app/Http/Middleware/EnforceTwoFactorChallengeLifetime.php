<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class EnforceTwoFactorChallengeLifetime
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $request->routeIs('two-factor.login.store')) {
            return $next($request);
        }

        $issuedAt = $request->session()->get('login.issued_at');
        $maximumAge = (int) config('security.two_factor_challenge_seconds', 300);

        if (! is_int($issuedAt) || now()->timestamp - $issuedAt > $maximumAge) {
            $request->session()->forget(['login.id', 'login.remember', 'login.issued_at']);
            $field = $request->filled('recovery_code') ? 'recovery_code' : 'code';

            throw ValidationException::withMessages([
                $field => [$field === 'code'
                    ? 'The provided two factor authentication code was invalid.'
                    : 'The provided two factor recovery code was invalid.'],
            ]);
        }

        $response = $next($request);

        if ($request->user('web') !== null) {
            $request->session()->forget(['login.issued_at', 'login.remember']);
        }

        return $response;
    }
}
