<?php

namespace App\Actions\Scheduling;

use App\Models\Equipment;
use App\Models\Location;
use App\Models\Room;
use App\Models\StaffProfile;
use App\Support\Scheduling\ScheduleConflictDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class SearchAvailableSlots
{
    private const MAX_CANDIDATES = 500;

    public function __construct(private readonly ScheduleConflictDetector $conflicts) {}

    /** @return list<array<string, mixed>> */
    public function handle(string $studioId, array $attributes): array
    {
        $from = CarbonImmutable::parse($attributes['from']);
        $to = CarbonImmutable::parse($attributes['to']);

        if ($from->diffInDays($to) > 31) {
            throw ValidationException::withMessages(['to' => 'Slot searches are limited to 31 days.']);
        }

        $duration = (int) $attributes['duration_minutes'];
        $step = (int) ($attributes['step_minutes'] ?? 15);
        $spanMinutes = $from->diffInMinutes($to);
        $candidateCount = max(0, intdiv(max(0, $spanMinutes - $duration), $step) + 1);

        if ($candidateCount > self::MAX_CANDIDATES) {
            throw ValidationException::withMessages([
                'to' => 'This slot grid exceeds 500 candidates. Narrow the range or increase the step size.',
            ]);
        }

        $capacity = (int) ($attributes['capacity'] ?? 1);
        $this->validateResources($studioId, $attributes);
        $results = [];

        for ($candidate = $from; $candidate->addMinutes($duration)->lessThanOrEqualTo($to); $candidate = $candidate->addMinutes($step)) {
            $endsAt = $candidate->addMinutes($duration);
            $conflicts = $this->conflicts->detect(
                $studioId, $candidate, $endsAt, $attributes['location_id'] ?? null,
                $attributes['staff_profile_ids'] ?? [], $attributes['room_ids'] ?? [], $attributes['equipment'] ?? [],
            );
            $conflicts = [...$conflicts, ...$this->conflicts->occurrenceCapacity(
                $studioId, $capacity, $attributes['room_ids'] ?? [],
            )];

            if (! collect($conflicts)->contains('severity', 'hard')) {
                $results[] = [
                    'starts_at' => $candidate->toAtomString(), 'ends_at' => $endsAt->toAtomString(),
                    'score' => count(array_filter($conflicts, fn (array $item): bool => $item['severity'] === 'soft')),
                    'warnings' => collect($conflicts)->where('severity', 'soft')->map(fn (array $item): array => Arr::only($item, ['code', 'message']))->values()->all(),
                ];
            }

        }

        usort($results, fn (array $left, array $right): int => [$left['score'], $left['starts_at']] <=> [$right['score'], $right['starts_at']]);

        return array_slice($results, 0, 50);
    }

    /** @param array<string, mixed> $attributes */
    private function validateResources(string $studioId, array $attributes): void
    {
        $locationId = $attributes['location_id'] ?? null;

        if ($locationId !== null && ! Location::query()->where('studio_id', $studioId)->where('active', true)->whereKey($locationId)->exists()) {
            throw ValidationException::withMessages(['location_id' => 'The slot location must be active in this studio.']);
        }

        $staffIds = $attributes['staff_profile_ids'] ?? [];
        if (StaffProfile::query()->where('studio_id', $studioId)->where('status', 'active')->whereIn('id', $staffIds)->count() !== count($staffIds)) {
            throw ValidationException::withMessages(['staff_profile_ids' => 'Every requested teacher must be active in this studio.']);
        }

        $roomIds = $attributes['room_ids'] ?? [];
        if (Room::query()->where('studio_id', $studioId)->where('location_id', $locationId)->where('active', true)
            ->whereIn('id', $roomIds)->count() !== count($roomIds)) {
            throw ValidationException::withMessages(['room_ids' => 'Every requested room must be active at the slot location.']);
        }

        $requirements = collect($attributes['equipment'] ?? []);
        $equipment = Equipment::query()->where('studio_id', $studioId)->where('location_id', $locationId)->where('active', true)
            ->whereIn('id', $requirements->pluck('equipment_id'))->get()->keyBy('id');

        foreach ($requirements as $index => $requirement) {
            $resource = $equipment->get($requirement['equipment_id']);
            if ($resource === null || (int) $requirement['quantity'] > (int) $resource->quantity) {
                throw ValidationException::withMessages([
                    "equipment.{$index}.quantity" => 'Requested equipment must be active at the location and cannot exceed stock.',
                ]);
            }
        }
    }
}
