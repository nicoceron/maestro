<?php

namespace App\Actions\Scheduling;

use App\Enums\EventOccurrenceStatus;
use App\Enums\EventSeriesStatus;
use App\Models\EventOccurrence;
use App\Models\EventSeries;
use App\Models\ScheduleChangeEvent;
use App\Models\SchedulingOutboxMessage;
use App\Models\User;
use App\Support\Scheduling\ScheduleProjectionIntents;
use App\Support\Scheduling\SchedulingIdempotency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ManageSchedulingHold
{
    public function __construct(private readonly SchedulingIdempotency $idempotency) {}

    public function convert(EventSeries $series, int $expectedVersion, string $idempotencyKey, User $actor): EventSeries
    {
        return $this->mutate($series, $expectedVersion, $idempotencyKey, $actor, true, 'schedule.hold_converted');
    }

    public function release(EventSeries $series, int $expectedVersion, string $idempotencyKey, User $actor): EventSeries
    {
        return $this->mutate($series, $expectedVersion, $idempotencyKey, $actor, false, 'schedule.hold_released');
    }

    public function expire(EventSeries $series): EventSeries
    {
        return $this->mutate($series, $series->version, 'system-expire:'.$series->getKey().':'.$series->version, null, false, 'schedule.hold_expired');
    }

    private function mutate(EventSeries $series, int $expectedVersion, string $idempotencyKey, ?User $actor, bool $convert, string $eventType): EventSeries
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('update', $series);
        }

        $operationHash = hash('sha256', implode('|', [$eventType, $series->getKey(), $expectedVersion]));

        return DB::transaction(function () use ($series, $expectedVersion, $idempotencyKey, $actor, $convert, $eventType, $operationHash): EventSeries {
            if ($actor !== null) {
                $claim = $this->idempotency->claim((string) $series->studio_id, $actor, $idempotencyKey, $operationHash);

                if ($claim->isCompleted()) {
                    return $this->idempotency->replay($claim, EventSeries::class);
                }
            } else {
                $claim = null;
            }

            $series = EventSeries::query()->lockForUpdate()->findOrFail($series->getKey());

            if ($series->version !== $expectedVersion) {
                if ($actor === null && ($series->hold_expires_at === null || $series->status !== EventSeriesStatus::Draft)) {
                    return $series;
                }

                throw ValidationException::withMessages(['version' => 'The hold changed after it was loaded.']);
            }

            if ($series->status !== EventSeriesStatus::Draft || $series->hold_expires_at === null) {
                throw ValidationException::withMessages(['hold' => 'This event series is not an active temporary hold.']);
            }

            if ($convert && $series->hold_expires_at->isPast()) {
                throw ValidationException::withMessages(['hold' => 'The hold has expired and cannot be converted.']);
            }

            $occurrences = EventOccurrence::query()->where('event_series_id', $series->getKey())->lockForUpdate()->get();
            $series->status = $convert ? EventSeriesStatus::Active : EventSeriesStatus::Canceled;
            $series->hold_expires_at = null;
            $series->version++;
            $series->save();
            $occurrenceIds = $occurrences->pluck('id')->all();
            EventOccurrence::query()->whereIn('id', $occurrenceIds)->update([
                'status' => $convert ? EventOccurrenceStatus::Scheduled : EventOccurrenceStatus::Canceled,
                'hold_expires_at' => null,
                'canceled_at' => $convert ? null : now(),
                'canceled_by_user_id' => $actor?->getAuthIdentifier(),
                'cancellation_reason' => $convert ? null : ($eventType === 'schedule.hold_expired' ? 'Temporary hold expired.' : 'Temporary hold released.'),
                'version' => DB::raw('version + 1'),
            ]);

            if (! $convert) {
                DB::table('event_occurrence_teachers')->whereIn('event_occurrence_id', $occurrenceIds)->update(['status' => 'canceled']);
                DB::table('event_occurrence_rooms')->whereIn('event_occurrence_id', $occurrenceIds)->update(['status' => 'canceled']);
                DB::table('event_occurrence_equipment')->whereIn('event_occurrence_id', $occurrenceIds)->update(['status' => 'canceled']);
                DB::table('event_occurrence_participants')->whereIn('event_occurrence_id', $occurrenceIds)->update(['status' => 'canceled']);
            }

            $projectionIntents = ScheduleProjectionIntents::forChange($eventType, ['convert' => $convert]);
            ScheduleChangeEvent::query()->create([
                'studio_id' => $series->studio_id, 'actor_id' => $actor?->getAuthIdentifier(),
                'idempotency_key' => $actor === null ? null : $idempotencyKey,
                'event_series_id' => $series->getKey(), 'event_type' => $eventType,
                'payload' => [
                    'event_series_id' => $series->getKey(),
                    'operation_hash' => $operationHash,
                    'projection_intents' => $projectionIntents,
                ],
                'occurred_at' => now(),
            ]);
            SchedulingOutboxMessage::query()->create([
                'studio_id' => $series->studio_id, 'dedupe_key' => $eventType.':'.$series->getKey().':'.$expectedVersion,
                'topic' => $eventType, 'aggregate_type' => 'event_series', 'aggregate_id' => $series->getKey(),
                'aggregate_version' => $series->version,
                'payload' => [
                    'event_series_id' => $series->getKey(),
                    'system' => $actor === null,
                    'projection_intents' => $projectionIntents,
                ],
                'available_at' => now(),
            ]);

            if ($claim !== null) {
                $this->idempotency->complete($claim, $series);
            }

            return $series->refresh();
        }, 3);
    }
}
