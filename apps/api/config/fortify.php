<?php

use App\Http\Middleware\NormalizeAuthenticationEmail;
use App\Http\Middleware\ThrottlePasswordResetRequests;
use Laravel\Fortify\Features;

return [
    'guard' => 'web',
    'middleware' => [
        'web',
        NormalizeAuthenticationEmail::class,
        ThrottlePasswordResetRequests::class,
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
        'passkeys' => null,
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
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
    ],
];
