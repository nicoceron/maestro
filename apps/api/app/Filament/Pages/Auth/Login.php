<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use App\Support\Auth\BrowserSessionRegistry;
use App\Support\Tenancy\RequestDatabaseContext;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\LoginRateLimiter;
use SensitiveParameter;

final class Login extends BaseLogin
{
    /** @param array<string, mixed> $data */
    protected function getCredentialsFromFormData(#[SensitiveParameter] array $data): array
    {
        return [
            'email' => User::normalizeEmail((string) $data['email']),
            'password' => $data['password'],
        ];
    }

    public function authenticate(): ?LoginResponse
    {
        $request = app(Request::class);
        $request->merge([
            'email' => User::normalizeEmail((string) ($this->data['email'] ?? '')),
        ]);

        $limiter = app(LoginRateLimiter::class);

        if ($limiter->tooManyAttempts($request)) {
            $seconds = $limiter->availableIn($request);
            Notification::make()
                ->danger()
                ->title('Too many sign-in attempts')
                ->body("Try again in {$seconds} seconds.")
                ->send();

            return null;
        }

        try {
            $response = parent::authenticate();
        } catch (ValidationException $exception) {
            if (blank($this->userUndertakingMultiFactorAuthentication)) {
                $limiter->increment($request);
            }

            throw $exception;
        }

        $user = Filament::auth()->user();

        if ($response !== null && $user instanceof User) {
            $limiter->clear($request);
            $databaseContext = app(RequestDatabaseContext::class);
            $databaseContext->activateUser($user);

            try {
                if (! $request->hasSession()) {
                    $request->setLaravelSession(app('session')->driver());
                }

                app(BrowserSessionRegistry::class)->touch($request, $user);
            } finally {
                $databaseContext->clearUser();
            }
        }

        return $response;
    }

    /**
     * Filament's inherited limiter counts every invocation, including successes.
     * The shared Fortify limiter above counts only credential failures and applies
     * Maestro's normalized account plus IP dimensions.
     */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
    {
        // Intentionally delegated to LoginRateLimiter in authenticate().
    }
}
