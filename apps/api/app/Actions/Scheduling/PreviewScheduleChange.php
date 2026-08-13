<?php

namespace App\Actions\Scheduling;

use App\Contracts\Scheduling\RecurrenceEngine;
use App\Enums\LocalTimeResolution;
use App\Enums\ScheduleEditScope;
use App\Enums\SchedulePreviewStatus;
use App\Models\Equipment;
use App\Models\EventOccurrence;
use App\Models\EventSeries;
use App\Models\Location;
use App\Models\Room;
use App\Models\ScheduleChangePreview;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Scheduling\RecurrenceSetTransformer;
use App\Support\Scheduling\ScheduleCommand;
use App\Support\Scheduling\ScheduleConflictDetector;
use App\Support\Scheduling\SchedulePreviewEnvelope;
use App\Support\Scheduling\ScheduleProjectionIntents;
use App\Support\Scheduling\SchedulingAccess;
use App\Support\Scheduling\ZonedLocalDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PreviewScheduleChange
{
    public function __construct(
        private readonly ScheduleConflictDetector $conflicts,
        private readonly SchedulingAccess $access,
        private readonly RecurrenceEngine $recurrence,
        private readonly RecurrenceSetTransformer $recurrences,
    ) {}

    /** @param array<string, mixed> $command */
    public function handle(
        EventOccurrence $occurrence,
        ScheduleEditScope $scope,
        array $command,
        bool $acknowledgeSoftWarnings,
        User $actor,
        string $commandType = 'reschedule',
    ): ScheduleChangePreview {
        $occurrence->loadMissing('series.teachers', 'series.rooms', 'series.equipmentRequirements');
        Gate::forUser($actor)->authorize('update', $occurrence);
        $series = $occurrence->series;
        $expectedVersion = (int) ($command['version'] ?? 0);

        if ($occurrence->version !== $expectedVersion) {
            throw ValidationException::withMessages(['version' => 'The occurrence changed after it was loaded.']);
        }

        unset($command['version']);
        if (array_key_exists('dtstart_local', $command)) {
            if (array_key_exists('starts_at_local', $command)) {
                throw ValidationException::withMessages(['dtstart_local' => 'Send only one recurrence start field.']);
            }
            $command['starts_at_local'] = $command['dtstart_local'];
            unset($command['dtstart_local']);
        }
        if (array_key_exists('dtstart_resolution', $command)) {
            if (array_key_exists('start_resolution', $command)) {
                throw ValidationException::withMessages(['dtstart_resolution' => 'Send only one recurrence resolution field.']);
            }
            $command['start_resolution'] = $command['dtstart_resolution'];
            unset($command['dtstart_resolution']);
        }
        if (array_key_exists('rrule', $command)) {
            $command['rrule'] = $this->recurrence->canonicalize($command['rrule']);
        }
        $this->validateScopeFields($scope, $command, $commandType);
        if ($scope === ScheduleEditScope::Series
            && array_intersect(array_keys($command), [
                'starts_at_local', 'start_resolution', 'timezone', 'location_id', 'duration_minutes',
                'rrule', 'rdates', 'exdates', 'teachers', 'room_ids', 'equipment',
            ]) !== []
            && EventOccurrence::query()->where('event_series_id', $series->getKey())
                ->where(fn ($query) => $query->where('starts_at', '<', now())->orWhere('status', 'completed'))->exists()) {
            throw ValidationException::withMessages(['scope' => 'A series with history must change its recurrence using the future scope.']);
        }
        $locationId = array_key_exists('location_id', $command) ? $command['location_id'] : $occurrence->location_id;
        $location = $locationId === null ? null : Location::query()
            ->where('studio_id', $series->studio_id)->where('active', true)->find($locationId);

        if ($locationId !== null && $location === null) {
            throw ValidationException::withMessages(['location_id' => 'The event location must be active in this studio.']);
        }

        if (array_key_exists('location_id', $command) && $locationId !== $occurrence->location_id) {
            if (! array_key_exists('room_ids', $command) && $occurrence->rooms()->where('status', 'assigned')->exists()) {
                throw ValidationException::withMessages(['room_ids' => 'Choose the rooms at the new location, or send an empty list.']);
            }

            if (! array_key_exists('equipment', $command) && $occurrence->equipmentReservations()->where('status', 'assigned')->exists()) {
                throw ValidationException::withMessages(['equipment' => 'Choose the equipment at the new location, or send an empty list.']);
            }
        }

        $timezone = $command['timezone'] ?? $location?->timezone ?? $occurrence->timezone;
        $localStart = $command['starts_at_local'] ?? (
            $timezone !== $occurrence->timezone
                ? $occurrence->starts_at->setTimezone($occurrence->timezone)->format('Y-m-d\TH:i:s')
                : null
        );
        $start = $localStart !== null
            ? ZonedLocalDateTime::resolve(
                $localStart,
                $timezone,
                LocalTimeResolution::from($command['start_resolution'] ?? 'reject'),
                'starts_at_local',
            )
            : $occurrence->starts_at;
        $duration = (int) ($command['duration_minutes'] ?? $occurrence->starts_at->diffInMinutes($occurrence->ends_at));
        $end = $start->addMinutes($duration);
        $staffIds = array_key_exists('teachers', $command)
            ? array_column($command['teachers'], 'staff_profile_id')
            : $series->teachers->pluck('staff_profile_id')->all();
        $roomIds = $command['room_ids'] ?? $series->rooms->pluck('room_id')->all();
        $equipment = $command['equipment'] ?? $series->equipmentRequirements->map(fn ($item): array => [
            'equipment_id' => (string) $item->equipment_id, 'quantity' => (int) $item->quantity,
        ])->all();
        $this->validateAssignments($series, $locationId, $staffIds, $roomIds, $equipment);
        $affectedOccurrences = $this->affectedOccurrences($series, $occurrence, $scope, $commandType);
        $deltaSeconds = $start->getTimestamp() - $occurrence->starts_at->getTimestamp();
        $conflicts = [];

        if ($commandType !== 'cancel') {
            $recurrenceShapeChange = $scope !== ScheduleEditScope::One
                && array_intersect(array_keys($command), ['starts_at_local', 'start_resolution', 'timezone', 'rrule', 'rdates', 'exdates']) !== [];
            $projections = $recurrenceShapeChange
                ? $this->recurrenceProjections($series, $occurrence, $command, $start)
                : $affectedOccurrences->map(fn (EventOccurrence $affected): array => [
                    'starts_at' => $localStart !== null ? $affected->starts_at->addSeconds($deltaSeconds) : $affected->starts_at,
                    'capacity' => (int) ($command['capacity'] ?? $affected->capacity),
                    'occurrence_id' => $affected->getKey(),
                ])->all();
            $ignoredOccurrenceIds = $affectedOccurrences->pluck('id')->all();

            foreach ($projections as $projected) {
                $projectedStart = $projected['starts_at'];
                $projectedEnd = $projectedStart->addMinutes($duration);
                $conflicts = [...$conflicts, ...$this->conflicts->detect(
                    $series->studio_id, $projectedStart, $projectedEnd,
                    $locationId, $staffIds, $roomIds, $equipment,
                    $recurrenceShapeChange ? $ignoredOccurrenceIds : $projected['occurrence_id'],
                )];
                $conflicts = [...$conflicts, ...$this->conflicts->occurrenceCapacity(
                    $series->studio_id,
                    $projected['capacity'],
                    $roomIds,
                    $projected['occurrence_id'],
                )];

                if ($commandType === 'restore' && $projected['occurrence_id'] !== null) {
                    $conflicts = [...$conflicts, ...$this->conflicts->restoredParticipantCapacity(
                        $series->studio_id, $projected['occurrence_id'], $projected['capacity'],
                    )];
                }
            }
            $conflicts = collect($conflicts)->unique(fn (array $item): string => $item['code'].':'.$item['resource_id'])->values()->all();
        }
        $hard = array_filter($conflicts, fn (array $conflict): bool => $conflict['severity'] === 'hard');
        $soft = array_filter($conflicts, fn (array $conflict): bool => $conflict['severity'] === 'soft');

        if ($acknowledgeSoftWarnings && ($soft === [] || ! $this->access->canManage($actor, $series->studio_id))) {
            throw ValidationException::withMessages([
                'acknowledge_soft_warnings' => 'Only scheduling managers may acknowledge active soft warnings.',
            ]);
        }

        $affected = $affectedOccurrences->count();
        $command = ScheduleCommand::canonical($command);
        $versions = ScheduleCommand::canonical($this->versions($series, $affectedOccurrences, $command));
        $projectionIntents = ScheduleProjectionIntents::forChange($commandType, $command);

        return ScheduleChangePreview::query()->create([
            'studio_id' => $series->studio_id,
            'actor_id' => $actor->getAuthIdentifier(),
            'event_series_id' => $series->getKey(),
            'event_occurrence_id' => $occurrence->getKey(),
            'command_type' => $commandType,
            'scope' => $scope,
            'command_hash' => SchedulePreviewEnvelope::hash(
                (string) $series->studio_id,
                $actor->getAuthIdentifier(),
                $commandType,
                $scope->value,
                $versions,
                $command,
            ),
            'soft_warning_fingerprint' => SchedulePreviewEnvelope::softWarningFingerprint($conflicts),
            'command' => $command,
            'aggregate_versions' => $versions,
            'impact' => [
                'affected_occurrences' => $affected,
                'effects' => array_values(array_filter([
                    'calendar_changed',
                    'notifications_projected',
                    ...ScheduleProjectionIntents::effectLabels($projectionIntents),
                ])),
                'projection_intents' => $projectionIntents,
            ],
            'conflicts' => array_values($conflicts),
            'status' => $hard === [] ? SchedulePreviewStatus::Ready : SchedulePreviewStatus::Blocked,
            'soft_warnings_acknowledged' => $soft !== [] && $acknowledgeSoftWarnings,
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    /** @return list<array{starts_at: CarbonImmutable, capacity: int, occurrence_id: null}> */
    private function recurrenceProjections(EventSeries $series, EventOccurrence $anchor, array $command, $resolvedStart): array
    {
        $recurrence = $this->recurrences->forFuture($series, $anchor->recurrence_id_local, $command);
        $timezone = (string) ($command['timezone'] ?? $series->timezone);
        $resolution = LocalTimeResolution::from((string) ($command['start_resolution'] ?? $series->dtstart_resolution->value));
        $seeds = $this->recurrence->expand(
            (string) ($command['starts_at_local'] ?? $anchor->recurrence_id_local),
            $timezone,
            $recurrence['new_rrule'],
            $resolvedStart->subDay(),
            $resolvedStart->addDays(92),
            startResolution: $resolution,
            rdates: $recurrence['new_rdates'],
            exdates: $recurrence['new_exdates'],
        );

        return array_map(fn ($seed): array => [
            'starts_at' => $seed->startsAt,
            'capacity' => (int) ($command['capacity'] ?? $series->capacity),
            'occurrence_id' => null,
        ], $seeds);
    }

    public function versions(EventSeries $series, $occurrences, array $command = []): array
    {
        $staffIds = array_key_exists('teachers', $command) ? array_column($command['teachers'], 'staff_profile_id') : [];
        $roomIds = $command['room_ids'] ?? [];
        $equipmentIds = array_column($command['equipment'] ?? [], 'equipment_id');

        return [
            'series' => $series->version,
            'occurrences' => $occurrences->pluck('version', 'id')->map(fn ($version) => (int) $version)->sortKeys()->all(),
            'teachers' => $series->teachers->pluck('updated_at', 'id')->map(fn ($value) => (string) $value)->sortKeys()->all(),
            'rooms' => $series->rooms->pluck('updated_at', 'id')->map(fn ($value) => (string) $value)->sortKeys()->all(),
            'equipment' => $series->equipmentRequirements->pluck('updated_at', 'id')->map(fn ($value) => (string) $value)->sortKeys()->all(),
            'referenced_staff' => StaffProfile::query()->whereIn('id', $staffIds)->pluck('updated_at', 'id')->map(fn ($value) => (string) $value)->sortKeys()->all(),
            'referenced_rooms' => Room::query()->whereIn('id', $roomIds)->pluck('version', 'id')->map(fn ($value) => (int) $value)->sortKeys()->all(),
            'referenced_equipment' => Equipment::query()->whereIn('id', $equipmentIds)->pluck('version', 'id')->map(fn ($value) => (int) $value)->sortKeys()->all(),
            'referenced_location' => array_key_exists('location_id', $command) && $command['location_id'] !== null
                ? Location::query()->where('studio_id', $series->studio_id)->whereKey($command['location_id'])->value('version')
                : null,
        ];
    }

    private function validateAssignments(EventSeries $series, ?string $locationId, array $staffIds, array $roomIds, array $equipment): void
    {
        if (StaffProfile::query()->where('studio_id', $series->studio_id)->whereIn('id', $staffIds)
            ->where('status', 'active')->count() !== count($staffIds)) {
            throw ValidationException::withMessages(['teachers' => 'Every assigned teacher must be active in this studio.']);
        }

        if (Room::query()->where('studio_id', $series->studio_id)->whereIn('id', $roomIds)
            ->where('location_id', $locationId)->where('active', true)->count() !== count($roomIds)) {
            throw ValidationException::withMessages(['room_ids' => 'Every room must be active at the event location.']);
        }

        $equipmentIds = array_column($equipment, 'equipment_id');

        if (Equipment::query()->where('studio_id', $series->studio_id)->whereIn('id', $equipmentIds)
            ->where('location_id', $locationId)->where('active', true)->count() !== count($equipmentIds)) {
            throw ValidationException::withMessages(['equipment' => 'Every equipment item must be active at the event location.']);
        }
    }

    private function validateScopeFields(ScheduleEditScope $scope, array $command, string $commandType): void
    {
        if (in_array($commandType, ['cancel', 'restore'], true)) {
            $allowed = $commandType === 'cancel'
                ? ['reason', 'makeup_required', 'makeup_reference']
                : ['reason'];
            $unsupported = array_diff(array_keys($command), $allowed);

            if ($unsupported !== []) {
                throw ValidationException::withMessages([
                    'scope' => 'These fields are not valid for this operation: '.implode(', ', $unsupported).'.',
                ]);
            }

            return;
        }

        $allowed = match ($scope) {
            ScheduleEditScope::One => ['starts_at_local', 'start_resolution', 'location_id', 'timezone', 'duration_minutes', 'title', 'capacity', 'makeup_required', 'makeup_reference', 'reason', 'teachers', 'room_ids', 'equipment'],
            ScheduleEditScope::Future => ['starts_at_local', 'start_resolution', 'timezone', 'location_id', 'duration_minutes', 'rrule', 'rdates', 'exdates', 'title', 'capacity', 'visibility', 'shared_description', 'internal_description', 'reason', 'teachers', 'room_ids', 'equipment'],
            ScheduleEditScope::Series => ['starts_at_local', 'start_resolution', 'timezone', 'location_id', 'duration_minutes', 'rrule', 'rdates', 'exdates', 'title', 'capacity', 'visibility', 'shared_description', 'internal_description', 'reason', 'teachers', 'room_ids', 'equipment'],
        };
        $unsupported = array_diff(array_keys($command), $allowed);

        if ($unsupported !== []) {
            throw ValidationException::withMessages([
                'scope' => 'These fields are not valid for the selected edit scope: '.implode(', ', $unsupported).'.',
            ]);
        }

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
}
