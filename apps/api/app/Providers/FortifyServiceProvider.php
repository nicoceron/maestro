<?php

namespace App\Providers;

use App\Actions\Fortify\CompletePasswordReset;
use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\NormalizeLoginEmail;
use App\Actions\Fortify\RedirectIfTwoFactorAuthenticatable;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\GenericPasswordResetLinkResponse;
use App\Models\User;
use App\Support\Auth\SecureLoginRateLimiter;
use App\Support\Auth\SecureTwoFactorAuthenticationProvider;
use App\Support\Auth\SensitiveRateLimitKey;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\NotPwnedVerifier;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CompletePasswordReset as FortifyCompletePasswordReset;
use Laravel\Fortify\Actions\EnsureLoginIsNotThrottled;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\RedirectsIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            FailedPasswordResetLinkRequestResponse::class,
            GenericPasswordResetLinkResponse::class,
        );
        $this->app->bind(
            SuccessfulPasswordResetLinkRequestResponse::class,
            GenericPasswordResetLinkResponse::class,
        );
        $this->app->bind(FortifyCompletePasswordReset::class, CompletePasswordReset::class);
        $this->app->singleton(LoginRateLimiter::class, SecureLoginRateLimiter::class);
        $this->app->singleton(TwoFactorAuthenticationProvider::class, SecureTwoFactorAuthenticationProvider::class);
        $this->app->scoped(RedirectsIfTwoFactorAuthenticatable::class, RedirectIfTwoFactorAuthenticatable::class);
        $this->app->singleton(
            UncompromisedVerifier::class,
            fn ($app): NotPwnedVerifier => new NotPwnedVerifier(
                $app[HttpFactory::class],
                (int) config('security.password_breach_timeout_seconds', 2),
            ),
        );
    }

    public function boot(): void
    {
        Event::listen(TwoFactorAuthenticationEnabled::class, function (TwoFactorAuthenticationEnabled $event): void {
            if ($event->user->two_factor_confirmed_at === null) {
                $event->user->forceFill(['two_factor_setup_started_at' => now()])->save();
            }
        });
        Event::listen(TwoFactorAuthenticationConfirmed::class, function (TwoFactorAuthenticationConfirmed $event): void {
            $event->user->forceFill(['two_factor_setup_started_at' => null])->save();
        });
        Event::listen(TwoFactorAuthenticationDisabled::class, function (TwoFactorAuthenticationDisabled $event): void {
            $event->user->forceFill(['two_factor_setup_started_at' => null])->save();
        });

        Password::defaults(fn (): Password => Password::min(12)
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->uncompromised());

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::authenticateThrough(fn (): array => array_filter([
            NormalizeLoginEmail::class,
            EnsureLoginIsNotThrottled::class,
            Features::enabled(Features::twoFactorAuthentication())
                ? RedirectsIfTwoFactorAuthenticatable::class
                : null,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]));

        RateLimiter::for('auth-forms', fn (Request $request): Limit => Limit::perMinute(60)
            ->by(app(SensitiveRateLimitKey::class)->for('auth-forms', (string) $request->ip())));

        RateLimiter::for('invitations', fn (Request $request): Limit => Limit::perMinute(30)
            ->by(app(SensitiveRateLimitKey::class)->for(
                'invitations',
                (string) ($request->user()?->getAuthIdentifier() ?? 'guest'),
                (string) $request->ip(),
            )));

        RateLimiter::for('two-factor', fn (Request $request): array => [
            Limit::perMinutes(5, 5)
                ->by(app(SensitiveRateLimitKey::class)->for(
                    'two-factor-challenge',
                    (string) ($request->session()->get('login.id') ?? 'guest'),
                    (string) $request->ip(),
                )),
            Limit::perHour(20)->by(app(SensitiveRateLimitKey::class)->for(
                'two-factor-ip',
                (string) $request->ip(),
            )),
        ]);

        RateLimiter::for('passkeys', fn (Request $request): array => [
            Limit::perMinutes(5, 10)
                ->by(app(SensitiveRateLimitKey::class)->for(
                    'passkeys-ceremony',
                    $request->session()->getId(),
                    (string) $request->ip(),
                )),
            Limit::perHour(50)->by(app(SensitiveRateLimitKey::class)->for(
                'passkeys-ip',
                (string) $request->ip(),
            )),
        ]);

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $query = http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], '', '&', PHP_QUERY_RFC3986);

            return rtrim((string) config('services.frontend.url'), '/').'/reset-password#'.$query;
        });
    }
}
