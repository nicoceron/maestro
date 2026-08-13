<?php

namespace App\TenantData\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTenantLifecycleRecentConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->hasSession()
            ? $request->session()->get('auth.password_confirmed_at', 0)
            : 0;
        $age = is_int($confirmedAt) && $confirmedAt > 0 ? now()->timestamp - $confirmedAt : -1;

        if ($age < 0 || $age > min((int) config('auth.password_timeout', 600), 300)) {
            return new JsonResponse([
                'message' => 'Recent identity confirmation is required.',
                'code' => 'RECENT_CONFIRMATION_REQUIRED',
            ], Response::HTTP_LOCKED);
        }

        $mfaVerifiedAt = $request->hasSession()
            ? $request->session()->get('auth.mfa_verified_at', 0)
            : 0;
        $mfaAge = is_int($mfaVerifiedAt) && $mfaVerifiedAt > 0
            ? now()->timestamp - $mfaVerifiedAt
            : -1;
        if ($mfaAge < 0 || $mfaAge > 300 || ! $request->user()?->hasEnabledTwoFactorAuthentication()) {
            return new JsonResponse([
                'message' => 'Multi-factor authentication is required for tenant data lifecycle operations.',
                'code' => 'MFA_REQUIRED',
            ], Response::HTTP_LOCKED);
        }

        return $next($request);
    }
}
