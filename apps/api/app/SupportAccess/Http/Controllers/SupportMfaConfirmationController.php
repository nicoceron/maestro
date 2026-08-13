<?php

namespace App\SupportAccess\Http\Controllers;

use App\Models\User;
use App\SupportAccess\SupportAccessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

final class SupportMfaConfirmationController
{
    public function __invoke(Request $request, TwoFactorAuthenticationProvider $provider): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'digits:6']]);
        $user = $request->user();
        if (! $user instanceof User || $user->two_factor_confirmed_at === null || $user->two_factor_secret === null
            || ! $provider->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $validated['code'])) {
            throw new SupportAccessException('support_mfa_invalid', 'The support MFA code was invalid.', 422);
        }

        $request->session()->put('support_access.mfa_verified_at', now()->timestamp);
        $expiresAt = now()->addSeconds((int) config('support-access.recent_auth_seconds', 600));

        return response()->json(['data' => ['verified' => true, 'expires_at' => $expiresAt->toIso8601String()]])
            ->header('Cache-Control', 'no-store, private');
    }
}
