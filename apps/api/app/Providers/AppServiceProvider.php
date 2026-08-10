<?php

namespace App\Providers;

use App\Support\Tenancy\RequestDatabaseContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(
            TenantContext::class,
            fn (): TenantContext => new TenantContext($this->app['db']->connection()),
        );
        $this->app->scoped(
            RequestDatabaseContext::class,
            fn (): RequestDatabaseContext => new RequestDatabaseContext($this->app['db']),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            $this->assertProductionSecurityLimits();
        }

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }

    private function assertProductionSecurityLimits(): void
    {
        $limits = [
            'AUTH_PASSWORD_TIMEOUT' => [(int) config('auth.password_timeout'), 600],
            'SESSION_LIFETIME' => [(int) config('security.session_idle_minutes'), (int) config('security.session_max_idle_minutes')],
            'SESSION_ABSOLUTE_LIFETIME_MINUTES' => [(int) config('security.session_absolute_minutes'), (int) config('security.session_max_absolute_minutes')],
            'TWO_FACTOR_CHALLENGE_SECONDS' => [(int) config('security.two_factor_challenge_seconds'), 300],
            'TWO_FACTOR_SETUP_SECONDS' => [(int) config('security.two_factor_setup_seconds'), 600],
        ];

        foreach ($limits as $name => [$configured, $maximum]) {
            if ($configured < 1 || $configured > $maximum) {
                throw new LogicException("{$name} must be between 1 and {$maximum} in production.");
            }
        }

        if (config('session.driver') !== 'database') {
            throw new LogicException('SESSION_DRIVER must be database in production.');
        }

        $passkeySecret = (string) config('fortify.passkeys.user_handle_secret');
        $appKey = (string) config('app.key');

        if ($passkeySecret === '' || hash_equals($appKey, $passkeySecret) || $this->secretBytes($passkeySecret) < 32) {
            throw new LogicException('PASSKEYS_USER_HANDLE_SECRET must be an independent secret of at least 32 bytes in production.');
        }

        $relyingParty = (string) config('fortify.passkeys.relying_party_id');
        $origins = config('fortify.passkeys.allowed_origins', []);

        if ($relyingParty === '' || ! is_array($origins) || $origins === []) {
            throw new LogicException('Passkey relying party and allowed origins must be configured in production.');
        }

        foreach ($origins as $origin) {
            $parts = is_string($origin) ? parse_url($origin) : false;

            if (! is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || ! isset($parts['host'])
                || rtrim($origin, '/') !== $origin) {
                throw new LogicException('PASSKEYS_ALLOWED_ORIGINS must contain exact HTTPS origins without trailing slashes in production.');
            }
        }
    }

    private function secretBytes(string $secret): int
    {
        if (! str_starts_with($secret, 'base64:')) {
            return strlen($secret);
        }

        $decoded = base64_decode(substr($secret, 7), true);

        return $decoded === false ? 0 : strlen($decoded);
    }
}
