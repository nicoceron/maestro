<?php

namespace App\Actions\Scheduling;

use App\Enums\EventEnrollmentStatus;
use App\Enums\EventOccurrenceStatus;
use App\Enums\EventParticipantStatus;
use App\Exceptions\SchedulingConflict;
use App\Models\EventEnrollment;
use App\Models\EventOccurrenceParticipant;
use App\Models\EventSeries;
use App\Models\Person;
use App\Models\ScheduleChangeEvent;
use App\Models\SchedulingOutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ManageEventEnrollment
{
    public function enroll(
        EventSeries $series,
        Person $person,
        EventEnrollmentStatus $status,
        string $idempotencyKey,
        User $actor,
        array $auditBinding = [],
    ): EventEnrollment {
        Gate::forUser($actor)->authorize('update', $series);
        $hash = hash('sha256', implode('|', ['enroll', $series->getKey(), $person->getKey(), $status->value]));

        return DB::transaction(function () use ($series, $person, $status, $idempotencyKey, $actor, $hash, $auditBinding): EventEnrollment {
            $existing = ScheduleChangeEvent::query()->where('studio_id', $series->studio_id)
                ->where('actor_id', $actor->getAuthIdentifier())->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if (($existing->payload['operation_hash'] ?? null) !== $hash) {
                    throw new SchedulingConflict('idempotency_key_reused', 'This Idempotency-Key was used for another roster operation.');
                }

                return EventEnrollment::query()->findOrFail($existing->payload['event_enrollment_id']);
            }

            $series = EventSeries::query()->lockForUpdate()->findOrFail($series->getKey());
            $person = Person::query()->where('studio_id', $series->studio_id)->findOrFail($person->getKey());
            $enrollment = EventEnrollment::query()
                ->where('studio_id', $series->studio_id)->where('event_series_id', $series->getKey())
                ->where('person_id', $person->getKey())->lockForUpdate()->first();

            if ($enrollment === null) {
                $enrollment = EventEnrollment::query()->create([
                    'studio_id' => $series->studio_id, 'event_series_id' => $series->getKey(),
                    'person_id' => $person->getKey(), 'status' => $status, 'role' => 'student', 'version' => 1,
                ]);
            } elseif ($enrollment->status !== $status) {
                $enrollment->status = $status;
                $enrollment->version++;
                $enrollment->save();
            }

            foreach ($series->occurrences()->whereIn('status', [EventOccurrenceStatus::Tentative, EventOccurrenceStatus::Scheduled])->lockForUpdate()->get() as $occurrence) {
                if ($status === EventEnrollmentStatus::Confirmed) {
                    $confirmed = EventOccurrenceParticipant::query()->where('event_occurrence_id', $occurrence->getKey())
                        ->where('person_id', '!=', $person->getKey())
                        ->whereIn('status', [EventParticipantStatus::Reserved, EventParticipantStatus::Confirmed])
                        ->lockForUpdate()->get(['id'])->count();

                    if ($confirmed >= $occurrence->capacity) {
                        throw new SchedulingConflict('participant_capacity', 'The event has no remaining participant capacity.');
                    }
                }

                EventOccurrenceParticipant::query()->updateOrCreate(
                    ['studio_id' => $series->studio_id, 'event_occurrence_id' => $occurrence->getKey(), 'person_id' => $person->getKey()],
                    [
                        'event_series_id' => $series->getKey(), 'event_enrollment_id' => $enrollment->getKey(),
                        'role' => 'student',
                        'status' => $status === EventEnrollmentStatus::Waitlisted ? EventParticipantStatus::Waitlisted : EventParticipantStatus::Confirmed,
                        'blocks_conflicts' => $status === EventEnrollmentStatus::Confirmed,
                        'busy_starts_at' => $occurrence->starts_at, 'busy_ends_at' => $occurrence->ends_at,
                    ],
                );
            }

            $this->record($series, $actor, $idempotencyKey, 'schedule.enrollment_changed', [
                'operation_hash' => $hash, 'event_enrollment_id' => $enrollment->getKey(), 'status' => $status->value, ...$auditBinding,
            ]);

            return $enrollment;
        }, 3);
    }

    public function withdraw(
        EventEnrollment $enrollment,
        int $expectedVersion,
        string $idempotencyKey,
        User $actor,
        array $auditBinding = [],
    ): EventEnrollment {
        $series = EventSeries::query()->findOrFail($enrollment->event_series_id);
        Gate::forUser($actor)->authorize('update', $series);

        $hash = hash('sha256', implode('|', ['withdraw', $series->getKey(), $enrollment->getKey(), $expectedVersion]));

        return DB::transaction(function () use ($enrollment, $series, $expectedVersion, $idempotencyKey, $actor, $hash, $auditBinding): EventEnrollment {
            $existing = ScheduleChangeEvent::query()->where('studio_id', $series->studio_id)
                ->where('actor_id', $actor->getAuthIdentifier())->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                if (($existing->payload['operation_hash'] ?? null) !== $hash) {
                    throw new SchedulingConflict('idempotency_key_reused', 'This Idempotency-Key was used for another roster operation.');
                }

                return EventEnrollment::query()->findOrFail($existing->payload['event_enrollment_id']);
            }

            $enrollment = EventEnrollment::query()->lockForUpdate()->findOrFail($enrollment->getKey());

            if ($enrollment->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'The enrollment changed after it was loaded.']);
            }

            $enrollment->status = EventEnrollmentStatus::Withdrawn;
            $enrollment->version++;
            $enrollment->save();
            EventOccurrenceParticipant::query()->where('event_enrollment_id', $enrollment->getKey())
                ->update(['status' => EventParticipantStatus::Canceled, 'blocks_conflicts' => false, 'version' => DB::raw('version + 1')]);
            $this->record($series, $actor, $idempotencyKey, 'schedule.enrollment_withdrawn', [
                'operation_hash' => $hash, 'event_enrollment_id' => $enrollment->getKey(), ...$auditBinding,
            ]);

            return $enrollment;
        }, 3);
    }

    private function record(EventSeries $series, User $actor, string $key, string $type, array $payload): void
    {
        ScheduleChangeEvent::query()->create([
            'studio_id' => $series->studio_id, 'event_series_id' => $series->getKey(),
            'actor_id' => $actor->getAuthIdentifier(), 'event_type' => $type,
            'idempotency_key' => $key, 'payload' => $payload, 'occurred_at' => now(),
        ]);
        SchedulingOutboxMessage::query()->create([
            'studio_id' => $series->studio_id, 'topic' => $type, 'aggregate_type' => 'event_series',
            'aggregate_id' => $series->getKey(), 'aggregate_version' => $series->version,
            'dedupe_key' => $type.':'.$key, 'payload' => ['event_series_id' => $series->getKey()], 'available_at' => now(),
        ]);
    }
}
