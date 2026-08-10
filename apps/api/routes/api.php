<?php

use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\HouseholdController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\StudioController;
use App\Http\Controllers\Api\V1\StudioInvitationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->group(function (): void {
        Route::post('invitations/preview', [InvitationAcceptanceController::class, 'show'])
            ->middleware('throttle:invitations');

        Route::middleware(['auth:sanctum', 'database.user-context', 'throttle:api'])->group(function (): void {
            Route::get('auth/user', CurrentUserController::class);
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
                            ->only(['index', 'store', 'destroy']);
                        Route::apiResource('households', HouseholdController::class)
                            ->only(['index', 'store', 'show']);
                    });
            });
        });
    });
