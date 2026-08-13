<?php

namespace App\Actions\Scheduling;

use App\Contracts\Scheduling\RecurrenceEngine;
use App\Enums\EventAssignmentRole;
use App\Enums\EventSeriesStatus;
use App\Enums\EventVisibility;
use App\Enums\LocalTimeResolution;
use App\Models\Equipment;
use App\Models\EventSeries;
use App\Models\EventSeriesEquipment;
use App\Models\EventSeriesRoom;
use App\Models\EventSeriesTeacher;
use App\Models\Room;
use App\Models\StaffProfile;
use App\Models\Studio;
use App\Models\User;
use App\Support\Scheduling\ZonedLocalDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateEventSeries
{
    public function __construct(
        private readonly RecurrenceEngine $recurrence,
        private readonly MaterializeEventSeries $materialize,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Studio $studio, array $attributes, User $actor): EventSeries
    {
        Gate::forUser($actor)->authorize('create', [EventSeries::class, $studio]);

        return DB::transaction(function () use ($studio, $attributes): EventSeries {
            if (isset($attributes['hold_expires_at'])) {
                $expires = CarbonImmutable::parse($attributes['hold_expires_at']);

                if ($expires->lessThanOrEqualTo(now()) || $expires->greaterThan(now()->addDays(7))) {
                    throw ValidationException::withMessages(['hold_expires_at' => 'Temporary holds must expire within seven days.']);
                }

                $attributes['hold_expires_at'] = $expires;
                $attributes['status'] = EventSeriesStatus::Draft;
            }

            $resolution = LocalTimeResolution::from($attributes['dtstart_resolution'] ?? 'reject');

            if ($resolution === LocalTimeResolution::NormalizeForward) {
                throw ValidationException::withMessages(['dtstart_resolution' => 'Explicit start times cannot normalize through a daylight-saving gap.']);
            }

            ZonedLocalDateTime::resolve($attributes['dtstart_local'], $attributes['timezone'], $resolution, 'dtstart_local');
            $attributes['rrule'] = $this->recurrence->canonicalize($attributes['rrule'] ?? null);
            $teachers = $attributes['teachers'] ?? [];
            $rooms = $attributes['room_ids'] ?? [];
            $equipment = $attributes['equipment'] ?? [];
            $clonedFrom = $attributes['source_series_id'] ?? null;
            unset($attributes['teachers'], $attributes['room_ids'], $attributes['equipment'], $attributes['source_series_id']);

            $series = EventSeries::query()->create([
                ...$attributes,
                'studio_id' => $studio->getKey(),
                'status' => $attributes['status'] ?? EventSeriesStatus::Active,
                'visibility' => $attributes['visibility'] ?? EventVisibility::Private,
                'dtstart_resolution' => $resolution,
                'cloned_from_series_id' => $clonedFrom,
            ]);

            foreach ($teachers as $assignment) {
                $staff = StaffProfile::query()->where('studio_id', $studio->getKey())->findOrFail($assignment['staff_profile_id']);
                $roles = array_map(fn ($role): string => $role instanceof \BackedEnum ? $role->value : $role, $staff->roles);

                if (! in_array('teacher', $roles, true) && ! in_array('substitute', $roles, true)) {
                    throw ValidationException::withMessages(['teachers' => 'Every assigned staff member must be a teacher or substitute.']);
                }

                EventSeriesTeacher::query()->create([
                    'studio_id' => $studio->getKey(),
                    'event_series_id' => $series->getKey(),
                    'staff_profile_id' => $staff->getKey(),
                    'role' => $assignment['role'] ?? EventAssignmentRole::Lead,
                ]);
            }

            foreach ($rooms as $roomId) {
                $room = Room::query()->where('studio_id', $studio->getKey())->where('location_id', $series->location_id)->findOrFail($roomId);
                EventSeriesRoom::query()->create([
                    'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(),
                    'location_id' => $series->location_id, 'room_id' => $room->getKey(),
                ]);
            }

            foreach ($equipment as $requirement) {
                $resource = Equipment::query()->where('studio_id', $studio->getKey())->where('location_id', $series->location_id)->findOrFail($requirement['equipment_id']);

                if ($requirement['quantity'] > $resource->quantity) {
                    throw ValidationException::withMessages(['equipment' => 'Requested equipment exceeds available stock.']);
                }

                EventSeriesEquipment::query()->create([
                    'studio_id' => $studio->getKey(), 'event_series_id' => $series->getKey(),
                    'location_id' => $series->location_id, 'equipment_id' => $resource->getKey(),
                    'quantity' => $requirement['quantity'],
                ]);
            }

            $this->materialize->handle(
                $series,
                ZonedLocalDateTime::resolve(
                    $series->dtstart_local, $series->timezone, $series->dtstart_resolution, 'dtstart_local',
                )->subDay(),
                ZonedLocalDateTime::resolve(
                    $series->dtstart_local, $series->timezone, $series->dtstart_resolution, 'dtstart_local',
                )->addDays(92),
            );

            return $series->refresh()->load(['teachers', 'rooms', 'equipmentRequirements', 'occurrences']);
        }, 3);
    }
}
