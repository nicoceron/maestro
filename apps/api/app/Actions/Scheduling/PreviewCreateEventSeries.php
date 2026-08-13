<?php

namespace App\Actions\Scheduling;

use App\Contracts\Scheduling\RecurrenceEngine;
use App\Enums\LocalTimeResolution;
use App\Enums\ScheduleEditScope;
use App\Enums\SchedulePreviewStatus;
use App\Models\EventSeries;
use App\Models\ScheduleChangePreview;
use App\Models\Studio;
use App\Models\User;
use App\Support\Scheduling\ScheduleCommand;
use App\Support\Scheduling\ScheduleConflictDetector;
use App\Support\Scheduling\SchedulePreviewEnvelope;
use App\Support\Scheduling\ScheduleProjectionIntents;
use App\Support\Scheduling\SchedulingAccess;
use App\Support\Scheduling\ZonedLocalDateTime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PreviewCreateEventSeries
{
    public function __construct(
        private readonly RecurrenceEngine $recurrence,
        private readonly ScheduleConflictDetector $conflicts,
        private readonly SchedulingAccess $access,
    ) {}

    /** @param array<string, mixed> $command */
    public function handle(Studio $studio, array $command, bool $acknowledgeSoftWarnings, User $actor, string $commandType = 'create_event_series'): ScheduleChangePreview
    {
        Gate::forUser($actor)->authorize('create', [EventSeries::class, $studio]);
        $resolution = LocalTimeResolution::from($command['dtstart_resolution'] ?? 'reject');
        $command['rrule'] = $this->recurrence->canonicalize($command['rrule'] ?? null);
        $firstStart = ZonedLocalDateTime::resolve(
            $command['dtstart_local'], $command['timezone'], $resolution, 'dtstart_local',
        );
        $seeds = $this->recurrence->expand(
            $command['dtstart_local'], $command['timezone'], $command['rrule'],
            $firstStart->subDay(), $firstStart->addDays(92),
            startResolution: $resolution,
            rdates: $command['rdates'] ?? [], exdates: $command['exdates'] ?? [],
        );
        $staffIds = array_column($command['teachers'] ?? [], 'staff_profile_id');
        $roomIds = $command['room_ids'] ?? [];
        $equipment = $command['equipment'] ?? [];
        $conflicts = [];

        foreach ($seeds as $seed) {
            $end = $seed->startsAt->addMinutes((int) $command['duration_minutes']);
            $conflicts = [...$conflicts, ...$this->conflicts->detect(
                $studio->getKey(), $seed->startsAt, $end, $command['location_id'] ?? null,
                $staffIds, $roomIds, $equipment,
            )];
            $conflicts = [...$conflicts, ...$this->conflicts->occurrenceCapacity(
                $studio->getKey(), (int) $command['capacity'], $roomIds,
            )];
        }

        $conflicts = collect($conflicts)->unique(fn (array $item): string => $item['code'].':'.$item['resource_id'])->values()->all();
        $hard = array_filter($conflicts, fn (array $item): bool => $item['severity'] === 'hard');
        $soft = array_filter($conflicts, fn (array $item): bool => $item['severity'] === 'soft');

        if ($acknowledgeSoftWarnings && ($soft === [] || ! $this->access->canManage($actor, $studio))) {
            throw ValidationException::withMessages(['acknowledge_soft_warnings' => 'Only scheduling managers may acknowledge active soft warnings.']);
        }

        $versions = ScheduleCommand::canonical($this->versions($studio->getKey(), $command));
        $command = ScheduleCommand::canonical($command);
        $scope = ScheduleEditScope::Series;
        $warningFingerprint = SchedulePreviewEnvelope::softWarningFingerprint($conflicts);
        $projectionIntents = ScheduleProjectionIntents::forChange($commandType, $command);

        return ScheduleChangePreview::query()->create([
            'studio_id' => $studio->getKey(), 'actor_id' => $actor->getAuthIdentifier(),
            'command_type' => $commandType, 'scope' => $scope,
            'command_hash' => SchedulePreviewEnvelope::hash(
                (string) $studio->getKey(), $actor->getAuthIdentifier(), $commandType, $scope->value, $versions, $command,
            ),
            'soft_warning_fingerprint' => $warningFingerprint,
            'command' => $command,
            'aggregate_versions' => $versions,
            'impact' => [
                'affected_occurrences' => count($seeds),
                'effects' => ['calendar_created', 'notifications_projected', ...ScheduleProjectionIntents::effectLabels($projectionIntents)],
                'projection_intents' => $projectionIntents,
            ],
            'conflicts' => $conflicts, 'status' => $hard === [] ? SchedulePreviewStatus::Ready : SchedulePreviewStatus::Blocked,
            'soft_warnings_acknowledged' => $soft !== [] && $acknowledgeSoftWarnings,
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    /** @return array<string, mixed> */
    public function versions(string $studioId, array $command): array
    {
        return [
            'service' => $this->version('services', $studioId, $command['service_id'] ?? null),
            'program_offering' => $this->version('program_offerings', $studioId, $command['program_offering_id'] ?? null),
            'location' => $this->version('locations', $studioId, $command['location_id'] ?? null),
            'staff' => $this->versionMap('staff_profiles', $studioId, array_column($command['teachers'] ?? [], 'staff_profile_id'), false),
            'rooms' => $this->versionMap('rooms', $studioId, $command['room_ids'] ?? []),
            'equipment' => $this->versionMap('equipment', $studioId, array_column($command['equipment'] ?? [], 'equipment_id')),
            'source_series' => $this->version('event_series', $studioId, $command['source_series_id'] ?? null),
        ];
    }

    private function version(string $table, string $studioId, ?string $id): ?int
    {
        return $id === null ? null : (int) DB::table($table)->where('studio_id', $studioId)->where('id', $id)->value('version');
    }

    private function versionMap(string $table, string $studioId, array $ids, bool $hasVersion = true): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table($table)->where('studio_id', $studioId)->whereIn('id', $ids)
            ->pluck($hasVersion ? 'version' : 'updated_at', 'id')->map(fn ($value) => (string) $value)->sortKeys()->all();
    }
}
