<?php

use App\DataPortability\Http\Controllers\CrmDataPortabilityController;
use App\DataPortability\Http\Middleware\EnsureCrmDataPortabilityStepUp;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/studios/{studio}/data-portability')->middleware(['auth:sanctum', 'database.user-context', 'session.lifetime', 'throttle:api', 'verified', 'studio.member', PreventSensitiveResponseCaching::class])->group(function (): void {
    Route::get('template', [CrmDataPortabilityController::class, 'template'])->name('api.v1.data-portability.template');
    Route::post('imports', [CrmDataPortabilityController::class, 'storeImport'])->middleware([EnsureCrmDataPortabilityStepUp::class, 'throttle:crm-data-portability'])->name('api.v1.data-portability.imports.store');
    Route::get('imports/{import}', [CrmDataPortabilityController::class, 'showImport'])->name('api.v1.data-portability.imports.show');
    Route::get('imports/{import}/rows', [CrmDataPortabilityController::class, 'rows'])->name('api.v1.data-portability.imports.rows');
    Route::post('imports/{import}/preview', [CrmDataPortabilityController::class, 'preview'])->name('api.v1.data-portability.imports.preview');
    Route::put('imports/{import}/resolutions', [CrmDataPortabilityController::class, 'resolve'])->middleware(EnsureCrmDataPortabilityStepUp::class)->name('api.v1.data-portability.imports.resolve');
    Route::post('imports/{import}/commit', [CrmDataPortabilityController::class, 'commit'])->middleware([EnsureCrmDataPortabilityStepUp::class, 'throttle:crm-data-portability'])->name('api.v1.data-portability.imports.commit');
    Route::post('imports/{import}/resume', [CrmDataPortabilityController::class, 'resume'])->middleware([EnsureCrmDataPortabilityStepUp::class, 'throttle:crm-data-portability'])->name('api.v1.data-portability.imports.resume');
    Route::get('imports/{import}/errors.csv', [CrmDataPortabilityController::class, 'errors'])->middleware(EnsureCrmDataPortabilityStepUp::class)->name('api.v1.data-portability.imports.errors');
    Route::post('exports', [CrmDataPortabilityController::class, 'storeExport'])->middleware([EnsureCrmDataPortabilityStepUp::class, 'throttle:crm-data-portability'])->name('api.v1.data-portability.exports.store');
    Route::get('exports/{export}', [CrmDataPortabilityController::class, 'showExport'])->name('api.v1.data-portability.exports.show');
    Route::post('exports/{export}/download-url', [CrmDataPortabilityController::class, 'downloadUrl'])->middleware(EnsureCrmDataPortabilityStepUp::class)->name('api.v1.data-portability.exports.download-url');
    Route::get('exports/{export}/download', [CrmDataPortabilityController::class, 'download'])->middleware(['signed', EnsureCrmDataPortabilityStepUp::class])->name('api.v1.data-portability.exports.download');
});
