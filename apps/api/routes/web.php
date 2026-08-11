<?php

use App\Http\Controllers\Auth\PublicRegistrationController;
use App\Http\Middleware\EnforceBrowserSessionLifetime;
use App\Http\Middleware\NormalizeAuthenticationEmail;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use Illuminate\Support\Facades\Route;

Route::post('/api/v1/auth/register', PublicRegistrationController::class)
    ->middleware([
        EnforceBrowserSessionLifetime::class,
        NormalizeAuthenticationEmail::class,
        PreventSensitiveResponseCaching::class,
        'guest:web',
        'throttle:registration',
        'throttle:auth-forms',
    ])
    ->name('register.store');

Route::get('/', function () {
    return view('welcome');
});
