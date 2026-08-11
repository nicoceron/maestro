<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRecentPasswordConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->hasSession()
            ? $request->session()->get('auth.password_confirmed_at', 0)
            : 0;
        $age = is_int($confirmedAt) && $confirmedAt > 0
            ? now()->timestamp - $confirmedAt
            : -1;

        if ($age < 0 || $age > (int) config('auth.password_timeout', 600)) {
            return new JsonResponse([
                'message' => 'Password confirmation required.',
            ], Response::HTTP_LOCKED);
        }

        return $next($request);
    }
}
