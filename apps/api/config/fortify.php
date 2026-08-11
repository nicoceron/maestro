<?php

use App\Http\Middleware\EnforceBrowserSessionLifetime;
use App\Http\Middleware\EnforceTwoFactorChallengeLifetime;
use App\Http\Middleware\GuardTwoFactorSetupMaterial;
use App\Http\Middleware\NormalizeAuthenticationEmail;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use App\Http\Middleware\ResetPendingTwoFactorChallengeOnLogin;
use App\Http\Middleware\SetDatabaseUserContext;
use App\Http\Middleware\ThrottlePasswordResetRequests;
use App\Http\Middleware\ValidatePasskeyLabel;
use Laravel\Fortify\Features;

$frontendUrl = rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/');
$passkeyOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => rtrim(trim($origin), '/'),
    explode(',', (string) env('PASSKEYS_ALLOWED_ORIGINS', $frontendUrl)),
)));
$appKey = (string) env('APP_KEY', '');
$passkeyUserHandleSecret = trim((string) env('PASSKEYS_USER_HANDLE_SECRET', ''));

if ($passkeyUserHandleSecret === '') {
    $passkeyUserHandleSecret = $appKey;
}

return [
    'guard' => 'web',
    'middleware' => [
        'web',
        EnforceBrowserSessionLifetime::class,
        SetDatabaseUserContext::class,
        NormalizeAuthenticationEmail::class,
        ResetPendingTwoFactorChallengeOnLogin::class,
        ThrottlePasswordResetRequests::class,
        EnforceTwoFactorChallengeLifetime::class,
        GuardTwoFactorSetupMaterial::class,
        ValidatePasskeyLabel::class,
        PreventSensitiveResponseCaching::class,
        'throttle:auth-forms',
    ],
    'auth_middleware' => 'auth',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'views' => false,
    'home' => env('FRONTEND_URL', 'http://localhost:3000'),
    'prefix' => 'api/v1/auth',
    'domain' => null,
    'lowercase_usernames' => true,
    'limiters' => [
        'login' => null,
        'two-factor' => 'two-factor',
        'passkeys' => 'passkeys',
        'verification' => '6,1',
    ],
    'paths' => [],
    'redirects' => [
        'login' => env('FRONTEND_URL', 'http://localhost:3000'),
        'logout' => env('FRONTEND_URL', 'http://localhost:3000'),
        'register' => env('FRONTEND_URL', 'http://localhost:3000'),
        'email-verification' => rtrim((string) env('FRONTEND_URL', 'http://localhost:3000'), '/').'/onboarding',
        'password-reset' => env('FRONTEND_URL', 'http://localhost:3000'),
    ],
    'passkeys' => [
        'relying_party_id' => env(
            'PASSKEYS_RELYING_PARTY_ID',
            parse_url($frontendUrl, PHP_URL_HOST),
        ),
        'allowed_origins' => $passkeyOrigins,
        'user_handle_secret' => $passkeyUserHandleSecret,
        'timeout' => (int) env('PASSKEYS_TIMEOUT_MILLISECONDS', 60000),
    ],
    'features' => [
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
            'window' => 1,
            'secret-length' => 32,
        ]),
        Features::passkeys(['confirmPassword' => true]),
    ],
];
