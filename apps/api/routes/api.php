<?php

use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\HouseholdController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PasskeyController;
use App\Http\Controllers\Api\V1\StudioController;
use App\Http\Controllers\Api\V1\StudioInvitationController;
use App\Http\Controllers\Api\V1\UserSessionController;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->group(function (): void {
        Route::post('invitations/preview', [InvitationAcceptanceController::class, 'show'])
            ->middleware('throttle:invitations');

        Route::middleware(['auth:sanctum', 'database.user-context', 'session.lifetime', 'throttle:api'])->group(function (): void {
            Route::prefix('auth')->middleware(PreventSensitiveResponseCaching::class)->group(function (): void {
                Route::get('user', CurrentUserController::class);
                Route::get('passkeys', PasskeyController::class);
                Route::get('sessions', [UserSessionController::class, 'index']);
                Route::delete('sessions/others', [UserSessionController::class, 'destroyOthers'])
                    ->middleware('password.recent');
                Route::delete('sessions/{userSession}', [UserSessionController::class, 'destroy'])
                    ->middleware('password.recent');
            });
            Route::post('invitations/accept', [InvitationAcceptanceController::class, 'accept'])
                ->middleware('throttle:invitations');
            Route::post('onboarding', OnboardingController::class);

            Route::middleware('verified')->group(function (): void {
                Route::apiResource('studios', StudioController::class)
                    ->only(['index', 'store', 'show']);

                Route::prefix('studios/{studio}')
                    ->middleware('studio.member')
                    ->group(function (): void {
                        Route::apiResource('invitations', StudioInvitationController::class)
                            ->only(['index']);
                        Route::post('invitations', [StudioInvitationController::class, 'store'])
                            ->middleware([
                                'password.recent',
                                'invitation.authorize:create',
                                'rate-limit.after-authorization:invitation-create',
                            ]);
                        Route::post('invitations/{invitation}/resend', [StudioInvitationController::class, 'resend'])
                            ->middleware([
                                'password.recent',
                                'invitation.authorize:resend',
                                'rate-limit.after-authorization:invitation-resend',
                            ]);
                        Route::delete('invitations/{invitation}', [StudioInvitationController::class, 'destroy'])
                            ->middleware('password.recent');
                        Route::apiResource('households', HouseholdController::class)
                            ->only(['index', 'store', 'show']);
                    });
            });
        });
    });
