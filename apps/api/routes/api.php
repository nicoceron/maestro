<?php

use App\Http\Controllers\Api\V1\HouseholdController;
use App\Http\Controllers\Api\V1\StudioController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'throttle:api'])
    ->prefix('v1')
    ->group(function (): void {
        Route::apiResource('studios', StudioController::class)
            ->only(['index', 'store', 'show']);

        Route::prefix('studios/{studio}')
            ->middleware('studio.member')
            ->group(function (): void {
                Route::apiResource('households', HouseholdController::class)
                    ->only(['index', 'store', 'show']);
            });
    });
