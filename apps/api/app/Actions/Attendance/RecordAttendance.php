<?php

namespace App\Actions\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\SchedulingOutboxMessage;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;
use App\Support\Attendance\AttendanceIdempotency;
use App\Support\Attendance\AttendancePayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordAttendance
{
    public function __construct(
        private readonly AttendanceAccess $access,
        private readonly AttendancePayload $payload,
        private readonly AttendanceIdempotency $idempotency,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(
        EventOccurrence $occurrence,
        EventOccurrenceParticipant $participant,
        array $attributes,
        User $actor,
        ?string $idempotencyKey = null,
    ): AttendanceRecord {
        abort_unless($this->access->canRecordAttendance($actor, $occurrence), 403);
        $key = $idempotencyKey === null ? null : $this->idempotency->key($idempotencyKey);
        $operation = 'attendance.record';
        $hash = $key === null ? null : $this->idempotency->hash((string) $occurrence->studio_id, $actor, $operation, [
            'occurrence_id' => $occurrence->getKey(),
            'participant_id' => $participant->getKey(),
            'attributes' => $attributes,
        ]);

        return DB::transaction(function () use ($occurrence, $participant, $attributes, $actor, $key, $operation, $hash): AttendanceRecord {
            if ($key !== null && $hash !== null) {
                $replay = $this->idempotency->replay((string) $occurrence->studio_id, $actor, $key, $operation, $hash);
                if ($replay !== null) {
                    $snapshot = (new AttendanceRecord)->newFromBuilder($replay->result_projection['attendance_record']);
                    $snapshot->setRelation('corrections', collect($replay->result_projection['corrections'])->map(
                        fn (array $attributes): AttendanceCorrection => (new AttendanceCorrection)->newFromBuilder($attributes),
                    ));

                    return $snapshot;
                }
            }
            $lockedOccurrence = EventOccurrence::query()->lockForUpdate()->findOrFail($occurrence->getKey());
            abort_unless($this->access->canRecordAttendance($actor, $lockedOccurrence), 403);
            $lockedParticipant = EventOccurrenceParticipant::query()
                ->where('studio_id', $lockedOccurrence->studio_id)
                ->where('event_occurrence_id', $lockedOccurrence->getKey())
                ->lockForUpdate()->findOrFail($participant->getKey());
            $normalized = $this->payload->normalize($attributes, $lockedOccurrence->policy_snapshot ?? []);

            $teacherCancellation = $normalized['outcome'] === 'teacher_cancelled';
            if ((! $teacherCancellation && $lockedOccurrence->ends_at->isFuture())
                || ! in_array($lockedOccurrence->status->value, $teacherCancellation ? ['canceled'] : ['scheduled', 'completed'], true)) {
                throw ValidationException::withMessages(['occurrence' => 'Attendance is available only after an eligible occurrence ends.']);
            }
            $eligibleParticipant = in_array($lockedParticipant->status->value, ['reserved', 'confirmed'], true)
                || ($teacherCancellation
                    && $lockedParticipant->status->value === 'canceled'
                    && in_array($lockedParticipant->getRawOriginal('previous_status'), ['reserved', 'confirmed'], true));
            if (! $eligibleParticipant) {
                throw ValidationException::withMessages(['participant' => 'Attendance may be recorded only for an active participant.']);
            }
            $record = AttendanceRecord::query()
                ->where('studio_id', $lockedOccurrence->studio_id)
                ->where('event_occurrence_participant_id', $lockedParticipant->getKey())
                ->lockForUpdate()->first();

            if ($record === null) {
                if (array_key_exists('version', $normalized)) {
                    throw ValidationException::withMessages(['version' => 'Version must be omitted for first attendance.']);
                }
                if (array_key_exists('correction_reason', $normalized)) {
                    throw ValidationException::withMessages(['correction_reason' => 'Correction reason is allowed only when correcting existing attendance.']);
                }

                $record = AttendanceRecord::query()->create([
                    ...$normalized,
                    'studio_id' => $lockedOccurrence->studio_id,
                    'event_occurrence_id' => $lockedOccurrence->getKey(),
                    'event_occurrence_participant_id' => $lockedParticipant->getKey(),
                    'person_id' => $lockedParticipant->person_id,
                    'recorded_by_user_id' => $actor->getAuthIdentifier(),
                    'recorded_at' => now(),
                    'version' => 1,
                ]);
                $record->refresh();
            } else {
                if ((int) ($normalized['version'] ?? 0) !== $record->version) {
                    throw ValidationException::withMessages(['version' => 'Attendance changed after it was opened.']);
                }

                $before = $this->values($record);
                $reason = $normalized['correction_reason'] ?? null;
                if (! is_string($reason) || trim($reason) === '') {
                    throw ValidationException::withMessages(['correction_reason' => 'A correction reason is required.']);
                }

                $recordedAt = now();
                $next = [
                    'outcome' => $normalized['outcome'],
                    'billing_disposition' => $normalized['billing_disposition'],
                    'makeup_disposition' => $normalized['makeup_disposition'],
                    'minutes_late' => $normalized['minutes_late'],
                    'reason' => $normalized['reason'],
                    'recorded_by_user_id' => $actor->getAuthIdentifier(),
                    'recorded_at' => $recordedAt->toAtomString(),
                ];
                AttendanceCorrection::query()->create([
                    'studio_id' => $record->studio_id,
                    'attendance_record_id' => $record->getKey(),
                    'previous_version' => $record->version,
                    'previous_values' => $before,
                    'new_values' => $next,
                    'reason' => trim($reason),
                    'actor_id' => $actor->getAuthIdentifier(),
                    'occurred_at' => now(),
                ]);
                $record->fill($normalized);
                $record->recorded_by_user_id = $actor->getAuthIdentifier();
                $record->recorded_at = $recordedAt;
                $record->version++;
                $record->save();
            }

            $this->projectEffects($record);

            if ($key !== null && $hash !== null) {
                $this->idempotency->store((string) $record->studio_id, $actor, $key, $operation, $hash, [
                    'attendance_record' => $record->getAttributes(),
                    'corrections' => AttendanceCorrection::query()
                        ->where('attendance_record_id', $record->getKey())->orderBy('previous_version')
                        ->get()->map->getAttributes()->all(),
                ]);
            }

            return $record->load('corrections');
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function values(AttendanceRecord $record): array
    {
        return [
            'outcome' => $record->outcome->value,
            'billing_disposition' => $record->billing_disposition->value,
            'makeup_disposition' => $record->makeup_disposition->value,
            'minutes_late' => $record->minutes_late,
            'reason' => $record->reason,
            'recorded_by_user_id' => $record->recorded_by_user_id,
            'recorded_at' => $record->recorded_at->toAtomString(),
        ];
    }

    private function projectEffects(AttendanceRecord $record): void
    {
        SchedulingOutboxMessage::query()->firstOrCreate(
            ['studio_id' => $record->studio_id, 'dedupe_key' => "attendance:{$record->getKey()}:v{$record->version}"],
            [
                'topic' => 'attendance.effects_projected',
                'aggregate_type' => 'attendance_record',
                'aggregate_id' => $record->getKey(),
                'aggregate_version' => $record->version,
                'payload' => [
                    'attendance_record_id' => $record->getKey(),
                    'billing' => ['disposition' => $record->billing_disposition->value],
                    'payroll' => ['disposition' => 'none'],
                    'makeup' => ['disposition' => $record->makeup_disposition->value],
                    'ledger_mutation' => false,
                ],
                'available_at' => now(),
            ],
        );
    }
}
