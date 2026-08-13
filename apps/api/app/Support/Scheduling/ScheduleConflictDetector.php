<?php

namespace App\Support\Scheduling;

use App\Enums\ApprovalStatus;
use App\Enums\AvailabilityEnforcement;
use App\Enums\AvailabilityOverrideType;
use App\Models\Equipment;
use App\Models\EventOccurrenceEquipment;
use App\Models\EventOccurrenceParticipant;
use App\Models\EventOccurrenceRoom;
use App\Models\EventOccurrenceTeacher;
use App\Models\Location;
use App\Models\Room;
use App\Models\StaffAvailabilityOverride;
use App\Models\StaffAvailabilityWindow;
use App\Models\StaffSchedulingProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ScheduleConflictDetector
{
    /**
     * @param  list<string>  $staffProfileIds
     * @param  list<string>  $roomIds
     * @param  list<array{equipment_id:string,quantity:int}>  $equipment
     * @return list<array{severity:string,code:string,resource_id:string,message:string}>
     */
    public function detect(
        string $studioId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?string $locationId,
        array $staffProfileIds,
        array $roomIds,
        array $equipment,
        string|array|null $ignoreOccurrenceId = null,
    ): array {
        $conflicts = [];

        foreach ($staffProfileIds as $staffId) {
            $profile = StaffSchedulingProfile::query()
                ->where('studio_id', $studioId)->where('staff_profile_id', $staffId)->where('active', true)->first();
            $busyStartsAt = $startsAt->subMinutes($profile?->default_buffer_before_minutes ?? 0);
            $busyEndsAt = $endsAt->addMinutes($profile?->default_buffer_after_minutes ?? 0);

            if ($this->overlaps(EventOccurrenceTeacher::query(), 'staff_profile_id', $staffId, $studioId, $busyStartsAt, $busyEndsAt, $ignoreOccurrenceId)) {
                $conflicts[] = $this->hard('teacher_overlap', $staffId, 'The teacher is already assigned during this time.');
            }

            $timeOff = StaffAvailabilityOverride::query()
                ->where('studio_id', $studioId)->where('staff_profile_id', $staffId)
                ->where('active', true)->where('kind', AvailabilityOverrideType::TimeOff)
                ->where('approval_status', ApprovalStatus::Approved)
                ->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt)->first();

            if ($timeOff !== null) {
                $conflicts[] = [
                    'severity' => 'hard',
                    'code' => 'teacher_time_off',
                    'resource_id' => $staffId,
                    'message' => 'The teacher has approved time off during this time.',
                ];
            }

            $approvedAvailability = StaffAvailabilityOverride::query()
                ->where('studio_id', $studioId)->where('staff_profile_id', $staffId)
                ->where('active', true)->where('kind', AvailabilityOverrideType::Available)
                ->where('approval_status', ApprovalStatus::Approved)
                ->where('starts_at', '<=', $startsAt)->where('ends_at', '>=', $endsAt)->exists();
            $windows = StaffAvailabilityWindow::query()
                ->where('studio_id', $studioId)->where('staff_profile_id', $staffId)
                ->where('active', true)->get();

            if (! $approvedAvailability && $windows->isNotEmpty()) {
                $matching = $windows->first(function ($window) use ($startsAt, $endsAt): bool {
                    $localStart = $startsAt->setTimezone($window->timezone);
                    $localEnd = $endsAt->setTimezone($window->timezone);

                    return $window->weekday === $localStart->dayOfWeekIso
                        && $window->start_time <= $localStart->format('H:i:s')
                        && $window->end_time >= $localEnd->format('H:i:s')
                        && $localStart->isSameDay($localEnd);
                });

                if ($matching === null) {
                    $severity = $windows->every(fn ($window): bool => $window->enforcement === AvailabilityEnforcement::Soft) ? 'soft' : 'hard';
                    $conflicts[] = [
                        'severity' => $severity,
                        'code' => 'outside_teacher_availability',
                        'resource_id' => $staffId,
                        'message' => 'The time is outside the teacher availability window.',
                    ];
                }
            }

            $this->detectWorkload($conflicts, $studioId, $staffId, $startsAt, $endsAt, $profile, $ignoreOccurrenceId);
            $this->detectTravel($conflicts, $studioId, $staffId, $busyStartsAt, $busyEndsAt, $locationId, $ignoreOccurrenceId);
        }

        foreach ($roomIds as $roomId) {
            if ($this->overlaps(EventOccurrenceRoom::query(), 'room_id', $roomId, $studioId, $startsAt, $endsAt, $ignoreOccurrenceId)) {
                $conflicts[] = $this->hard('room_overlap', $roomId, 'The room is already reserved during this time.');
            }
        }

        foreach ($equipment as $requirement) {
            $resource = Equipment::query()->where('studio_id', $studioId)->lockForUpdate()->find($requirement['equipment_id']);

            if ($resource === null || ! $resource->active) {
                $conflicts[] = $this->hard('equipment_unavailable', $requirement['equipment_id'], 'The equipment is unavailable.');

                continue;
            }

            $reserved = EventOccurrenceEquipment::query()
                ->where('studio_id', $studioId)->where('equipment_id', $resource->getKey())
                ->where('status', 'assigned')->where('busy_starts_at', '<', $endsAt)->where('busy_ends_at', '>', $startsAt)
                ->when($ignoreOccurrenceId, fn ($query) => is_array($ignoreOccurrenceId)
                    ? $query->whereNotIn('event_occurrence_id', $ignoreOccurrenceId)
                    : $query->where('event_occurrence_id', '<>', $ignoreOccurrenceId))
                ->sum('quantity');

            if ($reserved + $requirement['quantity'] > $resource->quantity) {
                $conflicts[] = $this->hard('equipment_capacity', (string) $resource->getKey(), 'The requested equipment quantity is not available.');
            }
        }

        return $conflicts;
    }

    /** @return list<array{severity:string,code:string,resource_id:string,message:string}> */
    public function occurrenceCapacity(string $studioId, int $capacity, array $roomIds, ?string $occurrenceId = null): array
    {
        $conflicts = [];
        $rooms = Room::query()->where('studio_id', $studioId)->whereIn('id', $roomIds)->lockForUpdate()->get();

        foreach ($rooms as $room) {
            if ($capacity > $room->capacity) {
                $conflicts[] = $this->hard('room_capacity', (string) $room->getKey(), 'The event capacity exceeds the room capacity.');
            }
        }

        if ($occurrenceId !== null) {
            $reserved = EventOccurrenceParticipant::query()
                ->where('studio_id', $studioId)->where('event_occurrence_id', $occurrenceId)
                ->whereIn('status', ['reserved', 'confirmed'])->lockForUpdate()->get(['id'])->count();

            if ($reserved > $capacity) {
                $conflicts[] = $this->hard('participant_capacity', $occurrenceId, 'Confirmed participants exceed the event capacity.');
            }
        }

        return $conflicts;
    }

    /** @return list<array{severity:string,code:string,resource_id:string,message:string}> */
    public function restoredParticipantCapacity(string $studioId, string $occurrenceId, int $capacity): array
    {
        $restored = EventOccurrenceParticipant::query()
            ->where('studio_id', $studioId)->where('event_occurrence_id', $occurrenceId)
            ->where('status', 'canceled')->whereIn('previous_status', ['reserved', 'confirmed'])
            ->lockForUpdate()->get(['id'])->count();

        return $restored > $capacity
            ? [$this->hard('participant_capacity', $occurrenceId, 'Restored participants would exceed the event capacity.')]
            : [];
    }

    public function lockResources(string $studioId, array $staffIds, array $roomIds, array $equipmentIds): void
    {
        $keys = array_map(static fn (string $id): string => $studioId.':'.$id, array_values(array_unique([
            ...$staffIds, ...$roomIds, ...$equipmentIds,
        ])));
        sort($keys);

        if (DB::getDriverName() === 'pgsql') {
            foreach ($keys as $key) {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [$key]);
            }
        }

        Equipment::query()->where('studio_id', $studioId)->whereIn('id', $equipmentIds)->orderBy('id')->lockForUpdate()->get();
    }

    private function overlaps($query, string $column, string $resourceId, string $studioId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, string|array|null $ignore): bool
    {
        return $query->where('studio_id', $studioId)->where($column, $resourceId)
            ->where('status', 'assigned')->where('busy_starts_at', '<', $endsAt)->where('busy_ends_at', '>', $startsAt)
            ->when($ignore, fn ($query) => is_array($ignore)
                ? $query->whereNotIn('event_occurrence_id', $ignore)
                : $query->where('event_occurrence_id', '<>', $ignore))->exists();
    }

    private function detectTravel(array &$conflicts, string $studioId, string $staffId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?string $locationId, string|array|null $ignore): void
    {
        if ($locationId === null) {
            return;
        }

        $neighbors = EventOccurrenceTeacher::query()
            ->where('event_occurrence_teachers.studio_id', $studioId)
            ->where('staff_profile_id', $staffId)->where('event_occurrence_teachers.status', 'assigned')
            ->when($ignore, fn ($query) => is_array($ignore)
                ? $query->whereNotIn('event_occurrence_id', $ignore)
                : $query->where('event_occurrence_id', '<>', $ignore))
            ->join('event_occurrences', function ($join): void {
                $join->on('event_occurrences.id', '=', 'event_occurrence_teachers.event_occurrence_id')
                    ->on('event_occurrences.studio_id', '=', 'event_occurrence_teachers.studio_id');
            })->whereNotNull('event_occurrences.location_id')
            ->where(function ($query) use ($startsAt, $endsAt): void {
                $query->whereBetween('event_occurrence_teachers.busy_ends_at', [$startsAt->subHours(4), $startsAt])
                    ->orWhereBetween('event_occurrence_teachers.busy_starts_at', [$endsAt, $endsAt->addHours(4)]);
            })->get(['event_occurrences.location_id', 'busy_starts_at', 'busy_ends_at']);

        foreach ($neighbors as $neighbor) {
            if ($neighbor->location_id === $locationId) {
                continue;
            }

            $locationKinds = Location::query()->where('studio_id', $studioId)
                ->whereIn('id', [$neighbor->location_id, $locationId])->pluck('kind', 'id');

            if ($locationKinds->get($neighbor->location_id) === 'online'
                && $locationKinds->get($locationId) === 'online') {
                continue;
            }

            $isPrevious = CarbonImmutable::parse($neighbor->busy_ends_at)->lessThanOrEqualTo($startsAt);
            [$from, $to] = $isPrevious
                ? [$neighbor->location_id, $locationId]
                : [$locationId, $neighbor->location_id];
            $minutes = DB::table('staff_travel_buffers')->where('studio_id', $studioId)->where('staff_profile_id', $staffId)
                ->where('active', true)->where('from_location_id', $from)->where('to_location_id', $to)->value('minutes');
            $minutes ??= StaffSchedulingProfile::query()
                ->where('studio_id', $studioId)->where('staff_profile_id', $staffId)->where('active', true)
                ->value('default_travel_buffer_minutes');
            $minutes ??= 0;
            $gap = $isPrevious
                ? CarbonImmutable::parse($neighbor->busy_ends_at)->diffInMinutes($startsAt)
                : $endsAt->diffInMinutes(CarbonImmutable::parse($neighbor->busy_starts_at));

            if ($gap < $minutes) {
                $conflicts[] = $this->hard('teacher_travel', $staffId, 'There is not enough travel time between locations.');
            }
        }
    }

    private function detectWorkload(
        array &$conflicts,
        string $studioId,
        string $staffId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?StaffSchedulingProfile $profile,
        string|array|null $ignoreOccurrenceId,
    ): void {
        if ($profile === null || ($profile->max_daily_minutes === null && $profile->max_weekly_minutes === null)) {
            return;
        }

        $zone = $profile->timezone;
        $local = $startsAt->setTimezone($zone);
        $dayStart = $local->startOfDay()->utc();
        $dayEnd = $local->endOfDay()->utc();
        $weekStart = $local->startOfWeek()->startOfDay()->utc();
        $weekEnd = $local->endOfWeek()->endOfDay()->utc();
        $minutes = $startsAt->diffInMinutes($endsAt);
        $assigned = EventOccurrenceTeacher::query()
            ->where('event_occurrence_teachers.studio_id', $studioId)
            ->where('staff_profile_id', $staffId)
            ->where('event_occurrence_teachers.status', 'assigned')
            ->when($ignoreOccurrenceId, fn ($query) => is_array($ignoreOccurrenceId)
                ? $query->whereNotIn('event_occurrence_id', $ignoreOccurrenceId)
                : $query->where('event_occurrence_id', '<>', $ignoreOccurrenceId))
            ->join('event_occurrences', function ($join): void {
                $join->on('event_occurrences.id', '=', 'event_occurrence_teachers.event_occurrence_id')
                    ->on('event_occurrences.studio_id', '=', 'event_occurrence_teachers.studio_id');
            });
        $daily = (clone $assigned)->where('event_occurrences.starts_at', '>=', $dayStart)
            ->where('event_occurrences.starts_at', '<=', $dayEnd)->get(['event_occurrences.starts_at', 'event_occurrences.ends_at'])
            ->sum(fn ($item): int => CarbonImmutable::parse($item->starts_at)->diffInMinutes(CarbonImmutable::parse($item->ends_at)));
        $weekly = (clone $assigned)->where('event_occurrences.starts_at', '>=', $weekStart)
            ->where('event_occurrences.starts_at', '<=', $weekEnd)->get(['event_occurrences.starts_at', 'event_occurrences.ends_at'])
            ->sum(fn ($item): int => CarbonImmutable::parse($item->starts_at)->diffInMinutes(CarbonImmutable::parse($item->ends_at)));

        if ($profile->max_daily_minutes !== null && $daily + $minutes > $profile->max_daily_minutes) {
            $conflicts[] = $this->hard('teacher_daily_limit', $staffId, 'The teacher daily scheduling limit would be exceeded.');
        }

        if ($profile->max_weekly_minutes !== null && $weekly + $minutes > $profile->max_weekly_minutes) {
            $conflicts[] = $this->hard('teacher_weekly_limit', $staffId, 'The teacher weekly scheduling limit would be exceeded.');
        }
    }

    private function hard(string $code, string $id, string $message): array
    {
        return ['severity' => 'hard', 'code' => $code, 'resource_id' => $id, 'message' => $message];
    }
}
