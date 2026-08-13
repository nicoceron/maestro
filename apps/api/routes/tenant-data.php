<?php

use App\DataLifecycle\Http\Controllers\TenantDeletionController;
use App\DataLifecycle\Http\Controllers\TenantRestoreDrillController;
use App\DataLifecycle\Http\Controllers\TenantRetentionPolicyController;
use App\TenantData\Http\Controllers\TenantDataExportController;
use App\TenantData\Http\Middleware\EnsureTenantLifecycleRecentConfirmation;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/studios/{studio}')
    ->middleware([
        'auth:sanctum',
        'database.user-context',
        'session.lifetime',
        'throttle:api',
        'verified',
    ])
    ->group(function (): void {
        Route::middleware('studio.member')->group(function (): void {
            Route::get('data-exports', [TenantDataExportController::class, 'index']);
            Route::post('data-exports', [TenantDataExportController::class, 'store'])
                ->middleware(EnsureTenantLifecycleRecentConfirmation::class);
            Route::get('data-exports/{export}', [TenantDataExportController::class, 'show']);
            Route::post('data-exports/{export}/download-url', [TenantDataExportController::class, 'downloadUrl'])
                ->middleware(EnsureTenantLifecycleRecentConfirmation::class);
            Route::get('data-exports/{export}/download', [TenantDataExportController::class, 'download'])
                ->middleware(['signed', EnsureTenantLifecycleRecentConfirmation::class])
                ->name('api.v1.tenant-data-exports.download');
            Route::post('data-exports/{export}/restore-drills', [TenantRestoreDrillController::class, 'store'])
                ->middleware(EnsureTenantLifecycleRecentConfirmation::class);
            Route::get('restore-drills/{drill}', [TenantRestoreDrillController::class, 'show']);

            Route::get('retention-policy', [TenantRetentionPolicyController::class, 'show']);
            Route::patch('retention-policy', [TenantRetentionPolicyController::class, 'update'])
                ->middleware(EnsureTenantLifecycleRecentConfirmation::class);
            Route::post('retention-policy/legal-hold', [TenantRetentionPolicyController::class, 'placeLegalHold'])
                ->middleware(EnsureTenantLifecycleRecentConfirmation::class);
            Route::delete('retention-policy/legal-hold', [TenantRetentionPolicyController::class, 'releaseLegalHold'])
                ->middleware(EnsureTenantLifecycleRecentConfirmation::class);

            Route::post('deletion-requests', [TenantDeletionController::class, 'store'])
                ->middleware(EnsureTenantLifecycleRecentConfirmation::class);
            Route::post('deletion-requests/{deletion}/approve', [TenantDeletionController::class, 'approve'])
                ->middleware(EnsureTenantLifecycleRecentConfirmation::class);
        });

        Route::middleware('studio.member:allow')->group(function (): void {
            Route::get('deletion-requests', [TenantDeletionController::class, 'index']);
            Route::get('deletion-requests/{deletion}', [TenantDeletionController::class, 'show']);
            Route::post('deletion-requests/{deletion}/cancel', [TenantDeletionController::class, 'cancel'])
                ->middleware(EnsureTenantLifecycleRecentConfirmation::class);
            Route::post('deletion-requests/{deletion}/restore', [TenantDeletionController::class, 'restore'])
                ->middleware(EnsureTenantLifecycleRecentConfirmation::class);
        });
    });
