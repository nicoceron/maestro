<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResetPendingTwoFactorChallengeOnLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->routeIs('login.store')) {
            $request->session()->forget(['login.id', 'login.remember', 'login.issued_at']);
        }

        return $next($request);
    }
}
