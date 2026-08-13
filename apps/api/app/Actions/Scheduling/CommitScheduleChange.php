<?php

namespace App\Actions\Scheduling;

use App\Enums\EventOccurrenceStatus;
use App\Enums\EventOverrideType;
use App\Enums\EventSeriesStatus;
use App\Enums\LocalTimeResolution;
use App\Enums\ScheduleEditScope;
use App\Enums\SchedulePreviewStatus;
use App\Exceptions\SchedulingConflict;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceEquipment;
use App\Models\EventOccurrenceOverride;
use App\Models\EventOccurrenceRoom;
use App\Models\EventOccurrenceTeacher;
use App\Models\EventSeries;
use App\Models\Location;
use App\Models\ScheduleChangeEvent;
use App\Models\ScheduleChangePreview;
use App\Models\SchedulingOutboxMessage;
use App\Models\StaffSchedulingProfile;
use App\Models\User;
use App\Support\Scheduling\RecurrenceSetTransformer;
use App\Support\Scheduling\ScheduleCommand;
use App\Support\Scheduling\ScheduleConflictDetector;
use App\Support\Scheduling\SchedulePreviewEnvelope;
use App\Support\Scheduling\ScheduleProjectionIntents;
use App\Support\Scheduling\SchedulingIdempotency;
use App\Support\Scheduling\ZonedLocalDateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CommitScheduleChange
{
    public function __construct(
        private readonly MaterializeEventSeries $materialize,
        private readonly ScheduleConflictDetector $conflicts,
        private readonly PreviewScheduleChange $previewAction,
        private readonly SchedulingIdempotency $idempotency,
        private readonly RecurrenceSetTransformer $recurrences,
    ) {}

    public function handle(ScheduleChangePreview $preview, string $idempotencyKey, User $actor): EventSeries
    {
        if ($preview->actor_id !== $actor->getAuthIdentifier()) {
            abort(404);
        }

        $authorizationOccurrence = EventOccurrence::query()->with('series')->findOrFail($preview->event_occurrence_id);
        Gate::forUser($actor)->authorize('update', $authorizationOccurrence);

        return DB::transaction(function () use ($preview, $idempotencyKey, $actor): EventSeries {
            $operationHash = $this->idempotency->operationHash(
                'schedule.change', $preview->getKey().'|'.$preview->command_hash,
            );
            $claim = $this->idempotency->claim((string) $preview->studio_id, $actor, $idempotencyKey, $operationHash);

            if ($claim->isCompleted()) {
                return $this->idempotency->replay($claim, EventSeries::class);
            }

            $preview = ScheduleChangePreview::query()->lockForUpdate()->findOrFail($preview->getKey());
            $command = $preview->command;
            SchedulePreviewEnvelope::assertValid($preview);

            if ($preview->consumed_at !== null) {
                throw new SchedulingConflict('preview_consumed', 'This schedule preview was already consumed.');
            }

            if ($preview->expires_at->isPast()) {
                throw new SchedulingConflict('preview_expired', 'This schedule preview expired. Generate a new preview.');
            }

            if ($preview->status === SchedulePreviewStatus::Blocked
                || collect($preview->conflicts)->contains('severity', 'hard')) {
                throw new SchedulingConflict('hard_scheduling_conflict', 'Hard scheduling conflicts cannot be overridden.');
            }

            if (collect($preview->conflicts)->contains('severity', 'soft') && ! $preview->soft_warnings_acknowledged) {
                throw new SchedulingConflict('soft_warning_unacknowledged', 'Soft warnings must be acknowledged by a scheduling manager.');
            }

            $canonical = ScheduleCommand::canonical($command);

            $series = EventSeries::query()->with(['teachers', 'rooms', 'equipmentRequirements'])->lockForUpdate()->findOrFail($preview->event_series_id);
            $occurrence = EventOccurrence::query()->lockForUpdate()->findOrFail($preview->event_occurrence_id);
            $affected = $this->affectedOccurrences($series, $occurrence, $preview->scope, $preview->command_type);

            if ($preview->aggregate_versions !== ScheduleCommand::canonical($this->previewAction->versions($series, $affected, $canonical))) {
                throw new SchedulingConflict('schedule_changed_after_preview', 'The schedule changed after preview. Generate a new preview.');
            }

            Gate::forUser($actor)->authorize('update', $occurrence);
            $scope = $preview->scope;
            $recurrenceShapeChange = $preview->command_type === 'reschedule'
                && $scope !== ScheduleEditScope::One
                && array_intersect(array_keys($canonical), [
                    'starts_at_local', 'start_resolution', 'timezone', 'rrule', 'rdates', 'exdates',
                ]) !== [];
            if ($recurrenceShapeChange) {
                $freshPreview = $this->previewAction->handle(
                    $occurrence,
                    $scope,
                    [...$canonical, 'version' => $occurrence->version],
                    false,
                    $actor,
                    $preview->command_type,
                );
                $freshFingerprint = $freshPreview->soft_warning_fingerprint;
                $freshStatus = $freshPreview->status;
                $freshPreview->delete();

                if ($freshStatus === SchedulePreviewStatus::Blocked) {
                    throw new SchedulingConflict('hard_scheduling_conflict', 'The recurrence developed a hard conflict after preview.');
                }
            } else {
                $freshConflicts = [];
            }
            if (! $recurrenceShapeChange && $preview->command_type !== 'cancel') {
                foreach ($affected as $affectedOccurrence) {
                    $freshConflicts = [
                        ...$freshConflicts,
                        ...$this->revalidate($series, $affectedOccurrence, $canonical, $occurrence, $preview->command_type === 'restore'),
                    ];
                }
            }
            if (! $recurrenceShapeChange) {
                $freshConflicts = collect($freshConflicts)
                    ->unique(fn (array $item): string => $item['code'].':'.$item['resource_id'])
                    ->values()
                    ->all();
                $freshFingerprint = SchedulePreviewEnvelope::softWarningFingerprint($freshConflicts);
            }

            if (! hash_equals(
                $preview->soft_warning_fingerprint,
                $freshFingerprint,
            )) {
                throw new SchedulingConflict('soft_warnings_changed', 'Soft scheduling warnings changed after preview. Generate and acknowledge a new preview.');
            }

            $auditBefore = $scope === ScheduleEditScope::One
                ? $this->auditProjection($occurrence)
                : null;

            if ($preview->command_type === 'cancel') {
                $this->cancel($series, $occurrence, $scope, $canonical, $actor);
            } elseif ($preview->command_type === 'restore') {
                $this->restore($series, $occurrence, $scope);
            } else {
                match ($scope) {
                    ScheduleEditScope::One => $this->applyOne($series, $occurrence, $canonical, $actor),
                    ScheduleEditScope::Future => $series = $this->applyFuture($series, $occurrence, $canonical, $actor),
                    ScheduleEditScope::Series => $series = $this->applySeries($series, $occurrence, $canonical, $actor),
                };
            }

            $result = $series->refresh();
            $result->wasRecentlyCreated = false;
            $auditAfter = $scope === ScheduleEditScope::One
                ? $this->auditProjection($occurrence->refresh())
                : null;
            $projectionIntents = ScheduleProjectionIntents::forChange($preview->command_type, $canonical);
            $preview->consumed_at = now();
            $preview->save();
            ScheduleChangeEvent::query()->create([
                'studio_id' => $series->studio_id,
                'event_series_id' => $series->getKey(),
                'event_occurrence_id' => $scope === ScheduleEditScope::One ? $occurrence->getKey() : null,
                'actor_id' => $actor->getAuthIdentifier(),
                'event_type' => 'schedule.'.$preview->command_type,
                'idempotency_key' => $idempotencyKey,
                'payload' => [
                    'scope' => $scope->value,
                    'preview_id' => $preview->getKey(),
                    'command_hash' => $preview->command_hash,
                    'makeup_required' => (bool) ($canonical['makeup_required'] ?? false),
                    'makeup_reference' => $canonical['makeup_reference'] ?? null,
                    'projection_intents' => $projectionIntents,
                    'before' => $auditBefore,
                    'after' => $auditAfter,
                ],
                'occurred_at' => now(),
            ]);
            SchedulingOutboxMessage::query()->create([
                'studio_id' => $series->studio_id,
                'topic' => 'schedule.changed',
                'aggregate_type' => 'event_series',
                'aggregate_id' => $series->getKey(),
                'aggregate_version' => $series->version,
                'dedupe_key' => 'schedule-change:'.$preview->getKey(),
                'payload' => [
                    'event_series_id' => $series->getKey(),
                    'scope' => $scope->value,
                    'makeup_required' => (bool) ($canonical['makeup_required'] ?? false),
                    'makeup_reference' => $canonical['makeup_reference'] ?? null,
                    'projection_intents' => $projectionIntents,
                ],
                'available_at' => now(),
            ]);
            $this->idempotency->complete($claim, $result);

            return $result;
        }, 3);
    }

    private function applyOne(EventSeries $series, EventOccurrence $occurrence, array $command, User $actor): void
    {
        $patch = array_intersect_key($command, array_flip(['starts_at_local', 'start_resolution', 'location_id', 'timezone', 'duration_minutes', 'title', 'capacity', 'makeup_required', 'makeup_reference', 'teachers', 'room_ids', 'equipment']));
        EventOccurrenceOverride::query()->updateOrCreate(
            ['studio_id' => $series->studio_id, 'event_series_id' => $series->getKey(), 'recurrence_id_local' => $occurrence->recurrence_id_local],
            ['event_occurrence_id' => $occurrence->getKey(), 'type' => EventOverrideType::Modified, 'patch' => $patch, 'reason' => $command['reason'] ?? null],
        );
        $locationId = array_key_exists('location_id', $command) ? $command['location_id'] : $occurrence->location_id;
        $location = $locationId === null ? null : Location::query()->where('studio_id', $series->studio_id)->findOrFail($locationId);
        $timezone = $command['timezone'] ?? $location?->timezone ?? $occurrence->timezone;
        $localStart = $command['starts_at_local'] ?? (
            $timezone !== $occurrence->timezone
                ? $occurrence->starts_at->setTimezone($occurrence->timezone)->format('Y-m-d\TH:i:s')
                : null
        );
        $start = $localStart !== null
            ? ZonedLocalDateTime::resolve(
                $localStart, $timezone,
                LocalTimeResolution::from($command['start_resolution'] ?? 'reject'), 'starts_at_local',
            ) : $occurrence->starts_at;
        $duration = (int) ($command['duration_minutes'] ?? $occurrence->starts_at->diffInMinutes($occurrence->ends_at));
        $pricingTeacherIds = array_key_exists('teachers', $command)
            ? array_column($command['teachers'], 'staff_profile_id')
            : $occurrence->teachers()->where('status', 'assigned')->pluck('staff_profile_id')->all();
        $snapshot = $this->materialize->resolvedSnapshot($series, $start, $locationId, $pricingTeacherIds);

        if ($locationId !== $occurrence->location_id) {
            // The composite location FKs intentionally make stale resource bindings impossible.
            // The override/audit event preserves the requested rebinding, while old reservations
            // must be removed before the occurrence can move to another location.
            $occurrence->rooms()->delete();
            $occurrence->equipmentReservations()->delete();
        }

        $occurrence->fill([
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($duration),
            'location_id' => $locationId,
            'timezone' => $timezone,
            'utc_offset_minutes' => intdiv($start->setTimezone($timezone)->getOffset(), 60),
            'source' => 'override',
            'title' => $command['title'] ?? $occurrence->title,
            'capacity' => $command['capacity'] ?? $occurrence->capacity,
            'makeup_required' => $command['makeup_required'] ?? $occurrence->makeup_required,
            'makeup_reference' => $command['makeup_reference'] ?? $occurrence->makeup_reference,
            'price_minor' => $snapshot['price_minor'],
            'currency' => $snapshot['currency'],
            'policy_snapshot' => $snapshot['policy_snapshot'],
            'source_snapshot' => $snapshot['source_snapshot'],
            'version' => $occurrence->version + 1,
        ]);
        $occurrence->save();
        $this->syncOccurrenceAssignments($series, $occurrence, $command, $start, $start->addMinutes($duration));
        $this->updateReservationTimes($occurrence, $start, $start->addMinutes($duration));
    }

    private function applyFuture(EventSeries $series, EventOccurrence $occurrence, array $command, User $actor): EventSeries
    {
        $newStartLocal = (string) ($command['dtstart_local'] ?? $command['starts_at_local'] ?? $occurrence->recurrence_id_local);
        $recurrence = $this->recurrences->forFuture($series, $occurrence->recurrence_id_local, $command);
        $series->rdates = $recurrence['old_rdates'];
        $series->exdates = $recurrence['old_exdates'];
        $series->recurrence_ends_before_local = $occurrence->recurrence_id_local;
        $series->version++;
        $series->save();
        EventOccurrence::query()->where('event_series_id', $series->getKey())
            ->where('recurrence_id_local', '>=', $occurrence->recurrence_id_local)
            ->whereIn('status', [EventOccurrenceStatus::Tentative, EventOccurrenceStatus::Scheduled])
            ->update(['status' => EventOccurrenceStatus::Canceled, 'canceled_at' => now(), 'canceled_by_user_id' => $actor->getAuthIdentifier(), 'cancellation_reason' => 'Series split.']);
        $futureIds = EventOccurrence::query()->where('event_series_id', $series->getKey())
            ->where('recurrence_id_local', '>=', $occurrence->recurrence_id_local)->pluck('id');
        $this->releaseReservations($futureIds->all());
        $new = $series->replicate(['id', 'created_at', 'updated_at', 'materialized_through']);
        $new->parent_series_id = $series->getKey();
        $new->split_from_recurrence_id_local = $occurrence->recurrence_id_local;
        $new->dtstart_local = $newStartLocal;
        $new->dtstart_resolution = $command['dtstart_resolution'] ?? $command['start_resolution'] ?? $series->dtstart_resolution;
        $new->timezone = $command['timezone'] ?? $series->timezone;
        $new->location_id = array_key_exists('location_id', $command) ? $command['location_id'] : $series->location_id;
        $new->rrule = $recurrence['new_rrule'];
        $new->rdates = $recurrence['new_rdates'];
        $new->exdates = $recurrence['new_exdates'];
        $new->recurrence_ends_before_local = null;
        $new->version = 1;
        $new->fill(array_intersect_key($command, array_flip([
            'duration_minutes', 'title', 'capacity',
            'visibility', 'shared_description', 'internal_description',
        ])));
        $new->save();

        $teachers = $command['teachers'] ?? $series->teachers->map(fn ($item): array => [
            'staff_profile_id' => $item->staff_profile_id, 'role' => $item->role->value,
        ])->all();
        $roomIds = $command['room_ids'] ?? $series->rooms->pluck('room_id')->all();
        $equipment = $command['equipment'] ?? $series->equipmentRequirements->map(fn ($item): array => [
            'equipment_id' => $item->equipment_id, 'quantity' => $item->quantity,
        ])->all();
        foreach ($teachers as $item) {
            $new->teachers()->create([
                'studio_id' => $new->studio_id, 'staff_profile_id' => $item['staff_profile_id'], 'role' => $item['role'] ?? 'lead',
            ]);
        }
        foreach ($roomIds as $roomId) {
            $new->rooms()->create(['studio_id' => $new->studio_id, 'location_id' => $new->location_id, 'room_id' => $roomId]);
        }
        foreach ($equipment as $item) {
            $new->equipmentRequirements()->create([
                'studio_id' => $new->studio_id, 'location_id' => $new->location_id,
                'equipment_id' => $item['equipment_id'], 'quantity' => $item['quantity'],
            ]);
        }
        foreach ($series->enrollments()->get() as $item) {
            $attributes = $item->only([
                'studio_id', 'person_id', 'role', 'status', 'begins_recurrence_id_local', 'ends_recurrence_id_local', 'version',
            ]);
            foreach (['begins_recurrence_id_local', 'ends_recurrence_id_local'] as $boundary) {
                if ($attributes[$boundary] !== null && $attributes[$boundary] >= $occurrence->recurrence_id_local) {
                    $attributes[$boundary] = $this->recurrences->translateIdentity(
                        $attributes[$boundary], $occurrence->recurrence_id_local, $newStartLocal,
                    );
                }
            }
            $new->enrollments()->create($attributes);
        }
        foreach (EventOccurrenceOverride::query()
            ->where('event_series_id', $series->getKey())
            ->where('recurrence_id_local', '>=', $occurrence->recurrence_id_local)->get() as $item) {
            $translatedIdentity = $this->recurrences->translateIdentity(
                $item->recurrence_id_local, $occurrence->recurrence_id_local, $newStartLocal,
            );
            EventOccurrenceOverride::query()->create([
                ...$item->only(['studio_id', 'recurrence_id_local', 'type', 'patch', 'reason', 'version']),
                'event_series_id' => $new->getKey(), 'event_occurrence_id' => null,
                'recurrence_id_local' => $translatedIdentity,
            ]);
        }
        DB::table('event_series_splits')->insert([
            'id' => (string) Str::ulid(), 'studio_id' => $series->studio_id,
            'old_series_id' => $series->getKey(), 'new_series_id' => $new->getKey(),
            'cutover_recurrence_id_local' => $occurrence->recurrence_id_local,
            'actor_id' => $actor->getAuthIdentifier(), 'occurred_at' => now(),
        ]);
        $materializationStart = ZonedLocalDateTime::resolve(
            $new->dtstart_local, $new->timezone, $new->dtstart_resolution, 'dtstart_local',
        );
        $this->materialize->handle($new, $materializationStart->subDay(), $materializationStart->addDays(92));

        return $new;
    }

    private function applySeries(EventSeries $series, EventOccurrence $occurrence, array $command, User $actor): EventSeries
    {
        $hasHistory = EventOccurrence::query()->where('event_series_id', $series->getKey())
            ->where(fn ($query) => $query->where('starts_at', '<', now())->orWhere('status', EventOccurrenceStatus::Completed))->exists();

        if ($hasHistory) {
            throw ValidationException::withMessages(['scope' => 'A series with history must be changed using the future scope.']);
        }

        if (array_intersect_key($command, array_flip([
            'starts_at_local', 'start_resolution', 'dtstart_local', 'dtstart_resolution', 'timezone',
            'duration_minutes', 'rrule', 'rdates', 'exdates', 'location_id', 'teachers', 'room_ids', 'equipment',
        ])) !== []) {
            return $this->applyFuture($series, $occurrence, $command, $actor);
        }

        $series->fill(array_intersect_key($command, array_flip(['title', 'capacity', 'visibility', 'shared_description', 'internal_description'])));
        $series->version++;
        $series->save();
        EventOccurrence::query()->where('event_series_id', $series->getKey())->where('starts_at', '>=', now())
            ->update([
                'title' => $command['title'] ?? $series->title,
                'capacity' => $command['capacity'] ?? $series->capacity,
                'version' => DB::raw('version + 1'),
            ]);

        return $series;
    }

    /** @return list<array<string, mixed>> */
    private function revalidate(EventSeries $series, EventOccurrence $occurrence, array $command, EventOccurrence $anchor, bool $restoring = false): array
    {
        $locationId = array_key_exists('location_id', $command) ? $command['location_id'] : $occurrence->location_id;
        $location = $locationId === null ? null : Location::query()->where('studio_id', $series->studio_id)->findOrFail($locationId);
        $timezone = $command['timezone'] ?? $location?->timezone ?? $anchor->timezone;
        $localStart = $command['starts_at_local'] ?? (
            $timezone !== $anchor->timezone
                ? $anchor->starts_at->setTimezone($anchor->timezone)->format('Y-m-d\TH:i:s')
                : null
        );
        $anchorStart = $localStart !== null
            ? ZonedLocalDateTime::resolve(
                $localStart, $timezone,
                LocalTimeResolution::from($command['start_resolution'] ?? 'reject'), 'starts_at_local',
            ) : $anchor->starts_at;
        $start = $localStart !== null
            ? $occurrence->starts_at->addSeconds($anchorStart->getTimestamp() - $anchor->starts_at->getTimestamp())
            : $occurrence->starts_at;
        $end = $start->addMinutes((int) ($command['duration_minutes'] ?? $occurrence->starts_at->diffInMinutes($occurrence->ends_at)));
        $staffIds = array_key_exists('teachers', $command)
            ? array_column($command['teachers'], 'staff_profile_id')
            : $series->teachers->pluck('staff_profile_id')->all();
        $roomIds = $command['room_ids'] ?? $series->rooms->pluck('room_id')->all();
        $equipment = $command['equipment'] ?? $series->equipmentRequirements->map(fn ($item): array => [
            'equipment_id' => (string) $item->equipment_id, 'quantity' => (int) $item->quantity,
        ])->all();
        $this->conflicts->lockResources($series->studio_id, $staffIds, $roomIds, array_column($equipment, 'equipment_id'));
        $conflicts = $this->conflicts->detect(
            $series->studio_id, $start, $end, $locationId,
            $staffIds, $roomIds, $equipment, $occurrence->getKey(),
        );
        $conflicts = [...$conflicts, ...$this->conflicts->occurrenceCapacity(
            $series->studio_id, (int) ($command['capacity'] ?? $occurrence->capacity), $roomIds, $occurrence->getKey(),
        )];

        if ($restoring) {
            $conflicts = [...$conflicts, ...$this->conflicts->restoredParticipantCapacity(
                $series->studio_id, $occurrence->getKey(), (int) $occurrence->capacity,
            )];
        }

        if (collect($conflicts)->contains('severity', 'hard')) {
            throw new SchedulingConflict('hard_scheduling_conflict', 'The schedule developed a hard conflict after preview.');
        }

        return $conflicts;
    }

    /** @return array<string, mixed> */
    private function auditProjection(EventOccurrence $occurrence): array
    {
        return [
            'event_occurrence_id' => (string) $occurrence->getKey(),
            'public_uid' => (string) $occurrence->public_uid,
            'version' => (int) $occurrence->version,
            'starts_at' => $occurrence->starts_at->toAtomString(),
            'ends_at' => $occurrence->ends_at->toAtomString(),
            'timezone' => (string) $occurrence->timezone,
            'location_id' => $occurrence->location_id,
            'teachers' => $occurrence->teachers()->where('status', 'assigned')->orderBy('staff_profile_id')->get()
                ->map(fn ($teacher): array => ['staff_profile_id' => (string) $teacher->staff_profile_id, 'role' => $teacher->role->value])->all(),
            'rooms' => $occurrence->rooms()->where('status', 'assigned')->orderBy('room_id')->pluck('room_id')->all(),
            'equipment' => $occurrence->equipmentReservations()->where('status', 'assigned')->orderBy('equipment_id')->get()
                ->map(fn ($equipment): array => ['equipment_id' => (string) $equipment->equipment_id, 'quantity' => (int) $equipment->quantity])->all(),
        ];
    }

    private function updateReservationTimes(EventOccurrence $occurrence, $start, $end): void
    {
        foreach ($occurrence->teachers()->get() as $teacher) {
            $profile = StaffSchedulingProfile::query()->where('staff_profile_id', $teacher->staff_profile_id)->where('active', true)->first();
            $teacher->update([
                'busy_starts_at' => $start->subMinutes($profile?->default_buffer_before_minutes ?? 0),
                'busy_ends_at' => $end->addMinutes($profile?->default_buffer_after_minutes ?? 0),
                'version' => $teacher->version + 1,
            ]);
        }
        $occurrence->rooms()->update(['busy_starts_at' => $start, 'busy_ends_at' => $end, 'version' => DB::raw('version + 1')]);
        $occurrence->equipmentReservations()->update(['busy_starts_at' => $start, 'busy_ends_at' => $end, 'version' => DB::raw('version + 1')]);
        $occurrence->participants()->update(['busy_starts_at' => $start, 'busy_ends_at' => $end, 'version' => DB::raw('version + 1')]);
    }

    private function syncOccurrenceAssignments(
        EventSeries $series,
        EventOccurrence $occurrence,
        array $command,
        $start,
        $end,
    ): void {
        if (array_key_exists('teachers', $command)) {
            $staffIds = array_column($command['teachers'], 'staff_profile_id');
            $occurrence->teachers()->whereNotIn('staff_profile_id', $staffIds)->update(['status' => 'canceled']);

            foreach ($command['teachers'] as $teacher) {
                $profile = StaffSchedulingProfile::query()->where('studio_id', $series->studio_id)
                    ->where('staff_profile_id', $teacher['staff_profile_id'])->where('active', true)->first();
                EventOccurrenceTeacher::query()->updateOrCreate(
                    [
                        'studio_id' => $series->studio_id, 'event_occurrence_id' => $occurrence->getKey(),
                        'staff_profile_id' => $teacher['staff_profile_id'],
                    ],
                    [
                        'role' => $teacher['role'] ?? 'lead', 'status' => 'assigned',
                        'busy_starts_at' => $start->subMinutes($profile?->default_buffer_before_minutes ?? 0),
                        'busy_ends_at' => $end->addMinutes($profile?->default_buffer_after_minutes ?? 0),
                    ],
                );
            }
        }

        if (array_key_exists('room_ids', $command)) {
            $occurrence->rooms()->whereNotIn('room_id', $command['room_ids'])->update(['status' => 'canceled']);

            foreach ($command['room_ids'] as $roomId) {
                EventOccurrenceRoom::query()->updateOrCreate(
                    ['studio_id' => $series->studio_id, 'event_occurrence_id' => $occurrence->getKey(), 'room_id' => $roomId],
                    ['location_id' => $occurrence->location_id, 'status' => 'assigned', 'busy_starts_at' => $start, 'busy_ends_at' => $end],
                );
            }
        }

        if (array_key_exists('equipment', $command)) {
            $ids = array_column($command['equipment'], 'equipment_id');
            $occurrence->equipmentReservations()->whereNotIn('equipment_id', $ids)->update(['status' => 'canceled']);

            foreach ($command['equipment'] as $equipment) {
                EventOccurrenceEquipment::query()->updateOrCreate(
                    [
                        'studio_id' => $series->studio_id, 'event_occurrence_id' => $occurrence->getKey(),
                        'equipment_id' => $equipment['equipment_id'],
                    ],
                    [
                        'location_id' => $occurrence->location_id, 'quantity' => $equipment['quantity'],
                        'status' => 'assigned', 'busy_starts_at' => $start, 'busy_ends_at' => $end,
                    ],
                );
            }
        }
    }

    private function releaseReservations(array $occurrenceIds): void
    {
        if ($occurrenceIds === []) {
            return;
        }

        DB::table('event_occurrence_teachers')->whereIn('event_occurrence_id', $occurrenceIds)->update([
            'previous_status' => DB::raw('status'), 'status' => 'canceled',
        ]);
        DB::table('event_occurrence_rooms')->whereIn('event_occurrence_id', $occurrenceIds)->update([
            'previous_status' => DB::raw('status'), 'status' => 'canceled',
        ]);
        DB::table('event_occurrence_equipment')->whereIn('event_occurrence_id', $occurrenceIds)->update([
            'previous_status' => DB::raw('status'), 'status' => 'canceled',
        ]);
        DB::table('event_occurrence_participants')->whereIn('event_occurrence_id', $occurrenceIds)->update([
            'previous_status' => DB::raw('status'),
            'status' => 'canceled',
            'blocks_conflicts' => false,
        ]);
    }

    private function affectedOccurrences(EventSeries $series, EventOccurrence $occurrence, ScheduleEditScope $scope, string $commandType)
    {
        $query = EventOccurrence::query()->where('event_series_id', $series->getKey());

        return match ($scope) {
            ScheduleEditScope::One => $query->whereKey($occurrence->getKey())->get(),
            ScheduleEditScope::Future => $query->where('recurrence_id_local', '>=', $occurrence->recurrence_id_local)
                ->when($commandType === 'cancel', fn ($query) => $query->whereIn('status', ['tentative', 'scheduled']))->get(),
            ScheduleEditScope::Series => $query->when($commandType === 'cancel', fn ($query) => $query->where('starts_at', '>=', now())->whereIn('status', ['tentative', 'scheduled']))->get(),
        };
    }

    private function cancel(EventSeries $series, EventOccurrence $occurrence, ScheduleEditScope $scope, array $command, User $actor): void
    {
        $query = EventOccurrence::query()->where('event_series_id', $series->getKey())
            ->whereIn('status', [EventOccurrenceStatus::Tentative, EventOccurrenceStatus::Scheduled]);
        match ($scope) {
            ScheduleEditScope::One => $query->whereKey($occurrence->getKey()),
            ScheduleEditScope::Future => $query->where('recurrence_id_local', '>=', $occurrence->recurrence_id_local),
            ScheduleEditScope::Series => $query->where('starts_at', '>=', now()),
        };
        $ids = $query->lockForUpdate()->pluck('id')->all();
        EventOccurrence::query()->whereIn('id', $ids)->update([
            'status' => EventOccurrenceStatus::Canceled, 'canceled_at' => now(),
            'canceled_by_user_id' => $actor->getAuthIdentifier(), 'cancellation_reason' => $command['reason'] ?? 'Canceled.',
            'makeup_required' => (bool) ($command['makeup_required'] ?? false),
            'makeup_reference' => $command['makeup_reference'] ?? null, 'version' => DB::raw('version + 1'),
        ]);
        $this->releaseReservations($ids);

        if ($scope === ScheduleEditScope::Series) {
            $series->status = EventSeriesStatus::Canceled;
            $series->version++;
            $series->save();
        } elseif ($scope === ScheduleEditScope::Future) {
            $series->recurrence_ends_before_local = $occurrence->recurrence_id_local;
            $series->version++;
            $series->save();
        }
    }

    private function restore(EventSeries $series, EventOccurrence $occurrence, ScheduleEditScope $scope): void
    {
        $query = EventOccurrence::query()->where('event_series_id', $series->getKey())->where('status', EventOccurrenceStatus::Canceled);
        match ($scope) {
            ScheduleEditScope::One => $query->whereKey($occurrence->getKey()),
            ScheduleEditScope::Future => $query->where('recurrence_id_local', '>=', $occurrence->recurrence_id_local),
            ScheduleEditScope::Series => $query->where('starts_at', '>=', now()),
        };
        $items = $query->lockForUpdate()->get();

        foreach ($items as $item) {
            $item->update([
                'status' => EventOccurrenceStatus::Scheduled, 'canceled_at' => null, 'canceled_by_user_id' => null,
                'cancellation_reason' => null, 'makeup_required' => false, 'makeup_reference' => null,
                'version' => $item->version + 1,
            ]);
            $item->teachers()->update(['status' => DB::raw("COALESCE(previous_status, 'assigned')"), 'previous_status' => null]);
            $item->rooms()->update(['status' => DB::raw("COALESCE(previous_status, 'assigned')"), 'previous_status' => null]);
            $item->equipmentReservations()->update(['status' => DB::raw("COALESCE(previous_status, 'assigned')"), 'previous_status' => null]);
            $item->participants()->update([
                'status' => DB::raw("COALESCE(previous_status, 'confirmed')"),
                'blocks_conflicts' => DB::raw("CASE WHEN COALESCE(previous_status, 'confirmed') IN ('reserved', 'confirmed') THEN TRUE ELSE FALSE END"),
                'previous_status' => null,
            ]);
        }

        if ($scope === ScheduleEditScope::Series) {
            $series->status = EventSeriesStatus::Active;
            $series->version++;
            $series->save();
        }
    }
}
