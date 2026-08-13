<?php

namespace App\Actions\Scheduling;

use App\Enums\EventEnrollmentStatus;
use App\Enums\SchedulePreviewStatus;
use App\Exceptions\SchedulingConflict;
use App\Models\EventEnrollment;
use App\Models\EventSeries;
use App\Models\Person;
use App\Models\ScheduleChangePreview;
use App\Models\User;
use App\Support\Scheduling\ScheduleCommand;
use App\Support\Scheduling\SchedulePreviewEnvelope;
use App\Support\Scheduling\SchedulingIdempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CommitEventEnrollment
{
    public function __construct(
        private readonly PreviewEventEnrollment $previews,
        private readonly ManageEventEnrollment $enrollments,
        private readonly SchedulingIdempotency $idempotency,
    ) {}

    public function handle(ScheduleChangePreview $preview, string $idempotencyKey, User $actor): EventEnrollment
    {
        if ($preview->actor_id !== $actor->getAuthIdentifier() || $preview->command_type !== 'enrollment_change') {
            abort(404);
        }

        $series = EventSeries::query()->findOrFail($preview->event_series_id);
        Gate::forUser($actor)->authorize('update', $series);

        return DB::transaction(function () use ($preview, $idempotencyKey, $actor, $series): EventEnrollment {
            $operationHash = $this->idempotency->operationHash(
                'schedule.roster', $preview->getKey().'|'.$preview->command_hash,
            );
            $claim = $this->idempotency->claim((string) $preview->studio_id, $actor, $idempotencyKey, $operationHash);

            if ($claim->isCompleted()) {
                return $this->idempotency->replay($claim, EventEnrollment::class);
            }

            $preview = ScheduleChangePreview::query()->lockForUpdate()->findOrFail($preview->getKey());
            SchedulePreviewEnvelope::assertValid($preview);

            if ($preview->consumed_at !== null) {
                throw new SchedulingConflict('preview_consumed', 'This schedule preview was already consumed.');
            }

            if ($preview->expires_at->isPast()) {
                throw new SchedulingConflict('preview_expired', 'This schedule preview expired. Generate a new preview.');
            }

            if ($preview->status === SchedulePreviewStatus::Blocked) {
                throw new SchedulingConflict('hard_scheduling_conflict', 'Hard scheduling conflicts cannot be overridden.');
            }

            $command = ScheduleCommand::canonical($preview->command);

            $series = EventSeries::query()->lockForUpdate()->findOrFail($series->getKey());

            if ($preview->aggregate_versions !== ScheduleCommand::canonical($this->previews->versions($series))) {
                throw new SchedulingConflict('schedule_changed_after_preview', 'The roster or schedule changed after preview.');
            }

            $auditBinding = ['preview_id' => $preview->getKey(), 'command_hash' => $preview->command_hash];
            $enrollment = match ($command['operation']) {
                'enroll' => $this->enrollments->enroll(
                    $series,
                    Person::query()->where('studio_id', $series->studio_id)->findOrFail($command['person_id']),
                    EventEnrollmentStatus::from($command['status']),
                    $idempotencyKey,
                    $actor,
                    $auditBinding,
                ),
                'withdraw' => $this->enrollments->withdraw(
                    EventEnrollment::query()->where('event_series_id', $series->getKey())->findOrFail($command['event_enrollment_id']),
                    (int) $command['expected_version'],
                    $idempotencyKey,
                    $actor,
                    $auditBinding,
                ),
                default => throw new SchedulingConflict('preview_command_mismatch', 'The roster command is invalid.'),
            };
            $preview->consumed_at = now();
            $preview->save();
            $this->idempotency->complete($claim, $enrollment);

            return $enrollment;
        }, 3);
    }
}
