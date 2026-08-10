<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Symfony\Component\HttpFoundation\Response;

final class GuardTwoFactorSetupMaterial
{
    public function __construct(private readonly DisableTwoFactorAuthentication $disable) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs(
            'two-factor.enable',
            'two-factor.confirm',
            'two-factor.qr-code',
            'two-factor.secret-key',
            'two-factor.recovery-codes',
            'two-factor.regenerate-recovery-codes',
        )) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($request->routeIs('two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes')
            && ($user->two_factor_secret === null || $user->two_factor_confirmed_at === null)) {
            abort(404, 'Two factor recovery codes are not available.');
        }

        if ($user->two_factor_secret === null) {
            return $next($request);
        }

        if ($user->two_factor_confirmed_at !== null) {
            if ($request->routeIs('two-factor.qr-code', 'two-factor.secret-key', 'two-factor.confirm')) {
                abort(404, 'Two factor setup material is not available.');
            }

            return $next($request);
        }

        $startedAt = $user->two_factor_setup_started_at;
        $expired = $startedAt === null
            || $startedAt->lte(now()->subSeconds((int) config('security.two_factor_setup_seconds', 600)));

        if ($expired) {
            ($this->disable)($user);

            if ($request->routeIs('two-factor.qr-code', 'two-factor.secret-key')) {
                abort(404, 'Two factor setup material is not available.');
            }
        }

        return $next($request);
    }
}
