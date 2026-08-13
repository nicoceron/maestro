<?php

namespace App\Actions\Scheduling;

use App\Contracts\Scheduling\RecurrenceEngine;
use App\Enums\EventOccurrenceStatus;
use App\Enums\EventOverrideType;
use App\Enums\EventSeriesStatus;
use App\Enums\LocalTimeResolution;
use App\Exceptions\SchedulingConflict;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceEquipment;
use App\Models\EventOccurrenceOverride;
use App\Models\EventOccurrenceParticipant;
use App\Models\EventOccurrenceRoom;
use App\Models\EventOccurrenceTeacher;
use App\Models\EventSeries;
use App\Models\ProgramOffering;
use App\Models\Service;
use App\Models\ServicePolicy;
use App\Models\ServicePrice;
use App\Models\StaffSchedulingProfile;
use App\Support\Scheduling\ResolveOfferingConfiguration;
use App\Support\Scheduling\ScheduleConflictDetector;
use App\Support\Scheduling\ZonedLocalDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MaterializeEventSeries
{
    public function __construct(
        private readonly RecurrenceEngine $recurrence,
        private readonly ScheduleConflictDetector $conflicts,
        private readonly ResolveOfferingConfiguration $offeringConfiguration,
    ) {}

    public function handle(EventSeries $series, CarbonImmutable $from, CarbonImmutable $through): int
    {
        if ($from->diffInDays($through) > 550) {
            throw ValidationException::withMessages(['through' => 'A materialization run cannot exceed 550 days.']);
        }

        return DB::transaction(function () use ($series, $from, $through): int {
            $series = EventSeries::query()->with(['teachers', 'rooms', 'equipmentRequirements', 'enrollments'])
                ->lockForUpdate()->findOrFail($series->getKey());
            $seeds = $this->recurrence->expand(
                $series->dtstart_local,
                $series->timezone,
                $series->rrule,
                $from,
                $through,
                startResolution: $series->dtstart_resolution,
                rdates: $series->rdates ?? [],
                exdates: $series->exdates ?? [],
            );
            $overrides = $series->hasMany(EventOccurrenceOverride::class)
                ->whereIn('recurrence_id_local', array_map(fn ($seed) => $seed->recurrenceIdLocal, $seeds))
                ->get()->keyBy('recurrence_id_local');
            $created = 0;

            foreach ($seeds as $seed) {
                if ($series->recurrence_ends_before_local !== null
                    && $seed->recurrenceIdLocal >= $series->recurrence_ends_before_local) {
                    continue;
                }

                $existing = EventOccurrence::query()
                    ->where('studio_id', $series->studio_id)
                    ->where('event_series_id', $series->getKey())
                    ->where('recurrence_id_local', $seed->recurrenceIdLocal)->first();

                if ($existing !== null) {
                    continue;
                }

                $override = $overrides->get($seed->recurrenceIdLocal);
                $patch = $override?->patch ?? [];
                $start = isset($patch['starts_at_local'])
                    ? ZonedLocalDateTime::resolve(
                        $patch['starts_at_local'],
                        $series->timezone,
                        LocalTimeResolution::from($patch['start_resolution'] ?? 'reject'),
                        'starts_at_local',
                    )
                    : $seed->startsAt;
                $resolved = $this->resolvedSnapshot($series, $start);
                $duration = (int) ($patch['duration_minutes'] ?? $resolved['resolved_duration_minutes']);
                $end = $start->addMinutes($duration);
                $locationId = $patch['location_id'] ?? $series->location_id;
                $status = $override?->type === EventOverrideType::Canceled
                    ? EventOccurrenceStatus::Canceled
                    : ($series->status === EventSeriesStatus::Draft ? EventOccurrenceStatus::Tentative : EventOccurrenceStatus::Scheduled);
                $teacherIds = $series->teachers->pluck('staff_profile_id')->all();
                $roomIds = $series->rooms->pluck('room_id')->all();
                $equipment = $series->equipmentRequirements->map(fn ($item): array => [
                    'equipment_id' => (string) $item->equipment_id,
                    'quantity' => (int) $item->quantity,
                ])->all();

                if ($status !== EventOccurrenceStatus::Canceled) {
                    $confirmedEnrollmentCount = $series->enrollments
                        ->filter(fn ($enrollment): bool => $enrollment->status->value === 'confirmed'
                            && ($enrollment->begins_recurrence_id_local === null || $seed->recurrenceIdLocal >= $enrollment->begins_recurrence_id_local)
                            && ($enrollment->ends_recurrence_id_local === null || $seed->recurrenceIdLocal <= $enrollment->ends_recurrence_id_local))
                        ->count();
                    $effectiveCapacity = (int) ($patch['capacity'] ?? $resolved['resolved_capacity']);

                    if ($confirmedEnrollmentCount > $effectiveCapacity) {
                        throw new SchedulingConflict('participant_capacity', 'Confirmed enrollment exceeds the occurrence capacity.');
                    }

                    $this->conflicts->lockResources($series->studio_id, $teacherIds, $roomIds, array_column($equipment, 'equipment_id'));
                    $conflicts = $this->conflicts->detect(
                        $series->studio_id, $start, $end, $locationId, $teacherIds, $roomIds, $equipment,
                    );
                    $conflicts = [...$conflicts, ...$this->conflicts->occurrenceCapacity(
                        $series->studio_id,
                        (int) ($patch['capacity'] ?? $resolved['resolved_capacity']),
                        $roomIds,
                    )];
                    $hard = array_values(array_filter($conflicts, fn (array $conflict): bool => $conflict['severity'] === 'hard'));

                    if ($hard !== []) {
                        throw ValidationException::withMessages(['conflicts' => array_map(fn (array $item): string => $item['message'], $hard)]);
                    }
                }

                $occurrence = EventOccurrence::query()->create([
                    'studio_id' => $series->studio_id,
                    'event_series_id' => $series->getKey(),
                    'location_id' => $locationId,
                    'public_uid' => (string) Str::uuid(),
                    'recurrence_id_local' => $seed->recurrenceIdLocal,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'utc_offset_minutes' => intdiv($start->setTimezone($series->timezone)->getOffset(), 60),
                    'timezone' => $series->timezone,
                    'source' => $override === null ? ($series->rrule === null ? 'one_off' : 'generated') : 'override',
                    'status' => $status,
                    'title' => $patch['title'] ?? $series->title,
                    'kind' => $series->kind,
                    'capacity' => $patch['capacity'] ?? $resolved['resolved_capacity'],
                    'makeup_required' => (bool) ($patch['makeup_required'] ?? false),
                    'makeup_reference' => $patch['makeup_reference'] ?? null,
                    'hold_expires_at' => $series->hold_expires_at,
                    'price_minor' => $resolved['price_minor'],
                    'currency' => $resolved['currency'],
                    'policy_snapshot' => $resolved['policy_snapshot'],
                    'source_snapshot' => $resolved['source_snapshot'],
                ]);

                if ($status !== EventOccurrenceStatus::Canceled) {
                    $this->reserve($series, $occurrence, $start, $end);
                    $this->projectEnrollments($series, $occurrence, $start, $end);
                }

                if ($override !== null) {
                    $override->event_occurrence_id = $occurrence->getKey();
                    $override->save();
                }

                $created++;
            }

            if ($series->materialized_through === null || $series->materialized_through->lessThan($through)) {
                $series->materialized_through = $through;
                $series->save();
            }

            return $created;
        }, 3);
    }

    /**
     * Resolve and freeze the commercial configuration used by an occurrence.
     *
     * @param  list<string>|null  $pricingTeacherIds
     * @return array<string, mixed>
     */
    public function resolvedSnapshot(
        EventSeries $series,
        CarbonImmutable $at,
        ?string $locationId = null,
        ?array $pricingTeacherIds = null,
    ): array {
        if ($series->service_id === null) {
            return [
                'resolved_duration_minutes' => $series->duration_minutes,
                'resolved_capacity' => $series->capacity,
                'price_minor' => 0,
                'currency' => $series->studio()->value('currency'),
                'policy_snapshot' => [],
                'source_snapshot' => ['event_series_version' => $series->version],
            ];
        }

        $service = Service::query()->findOrFail($series->service_id);
        $offering = $series->program_offering_id === null ? null : ProgramOffering::query()->with('service')->find($series->program_offering_id);

        if ($offering !== null) {
            $pricingTeacherIds ??= $series->pricing_staff_profile_id !== null
                ? [$series->pricing_staff_profile_id]
                : $series->teachers->pluck('staff_profile_id')->all();
            $configurations = collect($pricingTeacherIds === [] ? [null] : $pricingTeacherIds)
                ->map(fn (?string $staffId): array => $this->offeringConfiguration->resolve(
                    $offering,
                    $at->setTimezone($series->timezone),
                    $staffId,
                    $locationId ?? $series->location_id,
                ));
            $fingerprints = $configurations->map(fn (array $value): string => json_encode([
                $value['duration_minutes'], $value['capacity'], $value['price_minor'], $value['currency'],
                $value['booking_lead_minutes'], $value['cancellation_notice_minutes'], $value['makeup_policy'],
            ], JSON_THROW_ON_ERROR))->unique();

            if ($fingerprints->count() > 1) {
                throw ValidationException::withMessages([
                    'pricing_staff_profile_id' => 'Teacher-specific offering overrides differ; select the teacher whose configuration should be snapshotted.',
                ]);
            }

            $configuration = $configurations->first();

            return [
                'resolved_duration_minutes' => (int) $configuration['duration_minutes'],
                'resolved_capacity' => (int) $configuration['capacity'],
                'price_minor' => (int) $configuration['price_minor'],
                'currency' => $configuration['currency'],
                'policy_snapshot' => [
                    'booking_lead_minutes' => $configuration['booking_lead_minutes'],
                    'cancellation_notice_minutes' => $configuration['cancellation_notice_minutes'],
                    'makeup_policy' => $configuration['makeup_policy'],
                ],
                'source_snapshot' => [
                    'event_series_version' => $series->version,
                    'service_id' => $service->getKey(),
                    'service_version' => $service->version,
                    'program_offering_id' => $offering->getKey(),
                    'program_offering_version' => $offering->version,
                    'resolved_effective_on' => $configuration['effective_on'],
                    'resolved_sources' => $configuration['sources'],
                ],
            ];
        }
        $date = $at->setTimezone($series->timezone)->toDateString();
        $price = ServicePrice::query()->where('service_id', $service->getKey())->where('active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->orderByDesc('effective_from')->first();
        $policy = ServicePolicy::query()->where('service_id', $service->getKey())->where('active', true)
            ->where('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->orderByDesc('effective_from')->first();

        return [
            'resolved_duration_minutes' => $series->duration_minutes,
            'resolved_capacity' => $series->capacity,
            'price_minor' => $offering?->price_minor ?? $price?->amount_minor ?? $service->default_price_minor,
            'currency' => $offering?->currency ?? $price?->currency ?? $service->currency,
            'policy_snapshot' => [
                'booking_lead_minutes' => $policy?->booking_lead_minutes ?? $service->booking_lead_minutes,
                'cancellation_notice_minutes' => $policy?->cancellation_notice_minutes ?? $service->cancellation_notice_minutes,
                'makeup_policy' => ($policy?->makeup_policy ?? $service->makeup_policy)->value,
            ],
            'source_snapshot' => [
                'event_series_version' => $series->version,
                'service_id' => $service->getKey(),
                'service_version' => $service->version,
                'program_offering_id' => $offering?->getKey(),
                'program_offering_version' => $offering?->version,
            ],
        ];
    }

    private function reserve(EventSeries $series, EventOccurrence $occurrence, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        foreach ($series->teachers as $teacher) {
            $profile = StaffSchedulingProfile::query()->where('staff_profile_id', $teacher->staff_profile_id)->where('active', true)->first();
            EventOccurrenceTeacher::query()->create([
                'studio_id' => $series->studio_id,
                'event_occurrence_id' => $occurrence->getKey(),
                'staff_profile_id' => $teacher->staff_profile_id,
                'role' => $teacher->role,
                'busy_starts_at' => $startsAt->subMinutes($profile?->default_buffer_before_minutes ?? 0),
                'busy_ends_at' => $endsAt->addMinutes($profile?->default_buffer_after_minutes ?? 0),
            ]);
        }

        foreach ($series->rooms as $room) {
            EventOccurrenceRoom::query()->create([
                'studio_id' => $series->studio_id,
                'event_occurrence_id' => $occurrence->getKey(),
                'location_id' => $occurrence->location_id,
                'room_id' => $room->room_id,
                'busy_starts_at' => $startsAt,
                'busy_ends_at' => $endsAt,
            ]);
        }

        foreach ($series->equipmentRequirements as $equipment) {
            EventOccurrenceEquipment::query()->create([
                'studio_id' => $series->studio_id,
                'event_occurrence_id' => $occurrence->getKey(),
                'location_id' => $occurrence->location_id,
                'equipment_id' => $equipment->equipment_id,
                'quantity' => $equipment->quantity,
                'busy_starts_at' => $startsAt,
                'busy_ends_at' => $endsAt,
            ]);
        }
    }

    private function projectEnrollments(EventSeries $series, EventOccurrence $occurrence, CarbonImmutable $startsAt, CarbonImmutable $endsAt): void
    {
        foreach ($series->enrollments as $enrollment) {
            if ($enrollment->status->value === 'withdrawn'
                || ($enrollment->begins_recurrence_id_local !== null && $occurrence->recurrence_id_local < $enrollment->begins_recurrence_id_local)
                || ($enrollment->ends_recurrence_id_local !== null && $occurrence->recurrence_id_local > $enrollment->ends_recurrence_id_local)) {
                continue;
            }

            EventOccurrenceParticipant::query()->create([
                'studio_id' => $series->studio_id, 'event_occurrence_id' => $occurrence->getKey(),
                'event_series_id' => $series->getKey(), 'event_enrollment_id' => $enrollment->getKey(),
                'person_id' => $enrollment->person_id, 'role' => $enrollment->role,
                'status' => $enrollment->status->value === 'waitlisted' ? 'waitlisted' : 'confirmed',
                'blocks_conflicts' => $enrollment->status->value === 'confirmed',
                'busy_starts_at' => $startsAt, 'busy_ends_at' => $endsAt,
            ]);
        }
    }
}
