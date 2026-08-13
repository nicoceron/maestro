<?php

use App\Audit\Http\Controllers\TenantAuditController;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use App\SupportAccess\Http\Controllers\PlatformSupportAccessController;
use App\SupportAccess\Http\Controllers\SupportMfaConfirmationController;
use App\SupportAccess\Http\Controllers\SupportSessionAuditController;
use App\SupportAccess\Http\Controllers\TenantSupportAccessController;
use App\SupportAccess\Http\Middleware\EnsureCurrentSessionMfa;
use App\SupportAccess\Http\Middleware\ResolveSupportSession;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'database.user-context', 'session.lifetime', 'throttle:api', 'verified'])->group(function (): void {
    Route::prefix('studios/{studio}')->middleware('studio.member')->group(function (): void {
        Route::get('audit-events', TenantAuditController::class)->name('api.v1.audit-events.index');
        Route::get('support-access/grants', [TenantSupportAccessController::class, 'index'])->name('api.v1.support-access.grants.index');
        Route::post('support-access/grants/{grant}/approve', [TenantSupportAccessController::class, 'approve'])
            ->middleware(['password.recent', EnsureCurrentSessionMfa::class, PreventSensitiveResponseCaching::class])->name('api.v1.support-access.grants.approve');
        Route::post('support-access/grants/{grant}/reject', [TenantSupportAccessController::class, 'reject'])
            ->middleware(['password.recent', EnsureCurrentSessionMfa::class, PreventSensitiveResponseCaching::class])->name('api.v1.support-access.grants.reject');
        Route::post('support-access/grants/{grant}/revoke', [TenantSupportAccessController::class, 'revoke'])
            ->middleware(['password.recent', EnsureCurrentSessionMfa::class, PreventSensitiveResponseCaching::class])->name('api.v1.support-access.grants.revoke');
    });

    Route::prefix('platform/support-access')->middleware(PreventSensitiveResponseCaching::class)->group(function (): void {
        Route::post('mfa-confirmation', SupportMfaConfirmationController::class)
            ->middleware('password.recent')->name('api.v1.platform.support-access.mfa-confirmation');
        Route::get('grants', [PlatformSupportAccessController::class, 'index'])->name('api.v1.platform.support-access.grants.index');
        Route::post('requests', [PlatformSupportAccessController::class, 'store'])
            ->middleware(['password.recent', EnsureCurrentSessionMfa::class])->name('api.v1.platform.support-access.requests.store');
        Route::post('grants/{grant}/sessions', [PlatformSupportAccessController::class, 'start'])
            ->middleware(['password.recent', EnsureCurrentSessionMfa::class])->name('api.v1.platform.support-access.sessions.start');
        Route::delete('sessions/{session}', [PlatformSupportAccessController::class, 'end'])
            ->middleware(ResolveSupportSession::class.':session.manage')->name('api.v1.platform.support-access.sessions.end');
        Route::get('sessions/{session}/banner', [PlatformSupportAccessController::class, 'banner'])
            ->middleware(ResolveSupportSession::class.':session.manage')->name('api.v1.platform.support-access.sessions.banner');
        Route::get('sessions/{session}/audit-events', SupportSessionAuditController::class)
            ->middleware(ResolveSupportSession::class.':audit.read')->name('api.v1.platform.support-access.sessions.audit-events');
    });
});
