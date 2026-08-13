<?php

use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\HouseholdController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\LessonNoteAttachmentController;
use App\Http\Controllers\Api\V1\LessonNoteController;
use App\Http\Controllers\Api\V1\LessonNoteTemplateController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PasskeyController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\SchedulingController;
use App\Http\Controllers\Api\V1\StudioController;
use App\Http\Controllers\Api\V1\StudioInvitationController;
use App\Http\Controllers\Api\V1\UserSessionController;
use App\Http\Middleware\PreventSensitiveResponseCaching;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->group(function (): void {
        Route::post('invitations/preview', [InvitationAcceptanceController::class, 'show'])
            ->middleware('throttle:invitations');
        Route::get('public/studios/{studio}/calendar', [ScheduleController::class, 'publicCalendar'])
            ->middleware('throttle:api');

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
                        Route::patch('households/{household}', [HouseholdController::class, 'update']);
                        Route::apiResource('people', PersonController::class)
                            ->only(['index', 'store', 'show']);
                        Route::patch('people/{person}', [PersonController::class, 'update']);
                        Route::post('people/{person}/student-status', [PersonController::class, 'transitionStudentStatus']);
                        Route::get('scheduling/{resource}', [SchedulingController::class, 'index']);
                        Route::post('scheduling/{resource}', [SchedulingController::class, 'store']);
                        Route::get('scheduling/{resource}/{record}', [SchedulingController::class, 'show']);
                        Route::patch('scheduling/{resource}/{record}', [SchedulingController::class, 'update']);
                        Route::post('scheduling/availability-overrides/{record}/approval', [SchedulingController::class, 'updateOverrideApproval']);
                        Route::get('calendar', [ScheduleController::class, 'calendar']);
                        Route::post('event-series/previews', [ScheduleController::class, 'previewCreate']);
                        Route::post('event-series/previews/{preview}/commit', [ScheduleController::class, 'commitCreate']);
                        Route::get('event-series/{series}', [ScheduleController::class, 'showSeries']);
                        Route::post('event-series/{series}/clone/previews', [ScheduleController::class, 'previewClone']);
                        Route::post('event-series/{series}/hold/convert', [ScheduleController::class, 'convertHold']);
                        Route::delete('event-series/{series}/hold', [ScheduleController::class, 'releaseHold']);
                        Route::get('event-series/{series}/enrollments', [ScheduleController::class, 'roster']);
                        Route::post('event-series/{series}/enrollments/previews', [ScheduleController::class, 'previewEnrollment']);
                        Route::post('event-series/{series}/enrollments/{enrollment}/withdraw/previews', [ScheduleController::class, 'previewWithdrawal']);
                        Route::post('schedule/enrollment-previews/{preview}/commit', [ScheduleController::class, 'commitEnrollment']);
                        Route::post('schedule/slot-search', [ScheduleController::class, 'slotSearch']);
                        Route::post('occurrences/{occurrence}/reschedule/previews', [ScheduleController::class, 'previewChange']);
                        Route::post('occurrences/{occurrence}/cancel/previews', [ScheduleController::class, 'previewCancel']);
                        Route::post('occurrences/{occurrence}/restore/previews', [ScheduleController::class, 'previewRestore']);
                        Route::post('schedule/previews/{preview}/commit', [ScheduleController::class, 'commitChange']);
                        Route::get('attendance/overdue', [AttendanceController::class, 'overdue']);
                        Route::get('occurrences/{occurrence}/attendance', [AttendanceController::class, 'index']);
                        Route::put('occurrences/{occurrence}/participants/{participant}/attendance', [AttendanceController::class, 'store']);
                        Route::post('occurrences/{occurrence}/attendance/bulk', [AttendanceController::class, 'bulk']);
                        Route::post('occurrences/{occurrence}/attendance/express-present', [AttendanceController::class, 'express']);
                        Route::get('occurrences/{occurrence}/notes', [LessonNoteController::class, 'index']);
                        Route::post('occurrences/{occurrence}/notes', [LessonNoteController::class, 'store']);
                        Route::patch('notes/{note}', [LessonNoteController::class, 'update']);
                        Route::post('notes/{note}/attachments', [LessonNoteAttachmentController::class, 'store']);
                        Route::post('notes/{note}/attachments/{attachment}/scan', [LessonNoteAttachmentController::class, 'retryScan'])
                            ->middleware('throttle:attachment-scans');
                        Route::delete('notes/{note}/attachments/{attachment}', [LessonNoteAttachmentController::class, 'retire']);
                        Route::post('notes/{note}/attachments/{attachment}/download-url', [LessonNoteAttachmentController::class, 'downloadUrl']);
                        Route::get('notes/{note}/attachments/{attachment}/download', [LessonNoteAttachmentController::class, 'download'])
                            ->middleware(['signed', 'password.recent'])->name('api.v1.lesson-note-attachments.download');
                        Route::post('notes/{note}/delivery-previews', [LessonNoteController::class, 'previewDelivery']);
                        Route::post('note-delivery-previews/{preview}/commit', [LessonNoteController::class, 'commitDelivery']);
                        Route::get('note-templates', [LessonNoteTemplateController::class, 'index']);
                        Route::post('note-templates', [LessonNoteTemplateController::class, 'store']);
                        Route::patch('note-templates/{template}', [LessonNoteTemplateController::class, 'update']);
                    });
            });
        });
    });

require __DIR__.'/fragments/platform-operations-api.php';
require __DIR__.'/fragments/data-portability-api.php';
