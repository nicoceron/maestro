<?php

namespace App\SupportAccess\Http\Middleware;

use App\Models\User;
use App\SupportAccess\SupportAccessException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCurrentSessionMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $verifiedAt = $request->hasSession() ? $request->session()->get('support_access.mfa_verified_at') : null;
        $maximumAge = (int) config('support-access.recent_auth_seconds', 600);

        if (! $user instanceof User || $user->two_factor_confirmed_at === null || $user->two_factor_secret === null
            || ! is_int($verifiedAt) || $verifiedAt < 1 || now()->timestamp - $verifiedAt > $maximumAge) {
            throw new SupportAccessException('support_current_session_mfa_required', 'Complete a current-session MFA challenge before managing support access.', 423);
        }

        return $next($request);
    }
}
