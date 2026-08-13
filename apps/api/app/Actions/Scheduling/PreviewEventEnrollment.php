<?php

namespace App\Actions\Scheduling;

use App\Enums\EventEnrollmentStatus;
use App\Enums\EventOccurrenceStatus;
use App\Enums\EventParticipantStatus;
use App\Enums\ScheduleEditScope;
use App\Enums\SchedulePreviewStatus;
use App\Enums\StudentStatus;
use App\Models\EventEnrollment;
use App\Models\EventOccurrenceParticipant;
use App\Models\EventSeries;
use App\Models\Person;
use App\Models\ScheduleChangePreview;
use App\Models\User;
use App\Support\Scheduling\ScheduleCommand;
use App\Support\Scheduling\SchedulePreviewEnvelope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class PreviewEventEnrollment
{
    public function enroll(EventSeries $series, Person $person, EventEnrollmentStatus $status, User $actor): ScheduleChangePreview
    {
        Gate::forUser($actor)->authorize('update', $series);

        if ($person->studio_id !== $series->studio_id) {
            abort(404);
        }

        if ($person->trashed() || $person->status->value !== 'active'
            || ! $person->studentProfile()->where('status', StudentStatus::Active)->exists()) {
            throw ValidationException::withMessages(['person_id' => 'Only active students may be confirmed or waitlisted.']);
        }

        $occurrences = $this->activeOccurrences($series);
        $conflicts = [];

        if ($status === EventEnrollmentStatus::Confirmed) {
            foreach ($occurrences as $occurrence) {
                $confirmed = EventOccurrenceParticipant::query()
                    ->where('event_occurrence_id', $occurrence->getKey())
                    ->where('person_id', '!=', $person->getKey())
                    ->whereIn('status', [EventParticipantStatus::Reserved, EventParticipantStatus::Confirmed])
                    ->count();

                if ($confirmed >= $occurrence->capacity) {
                    $conflicts[] = [
                        'severity' => 'hard',
                        'code' => 'participant_capacity',
                        'message' => 'At least one occurrence has no remaining participant capacity.',
                        'resource_id' => $occurrence->getKey(),
                    ];
                    break;
                }
            }
        }

        return $this->store($series, [
            'operation' => 'enroll',
            'person_id' => $person->getKey(),
            'status' => $status->value,
        ], $occurrences, $conflicts, $actor);
    }

    public function withdraw(EventEnrollment $enrollment, int $expectedVersion, User $actor): ScheduleChangePreview
    {
        $series = EventSeries::query()->findOrFail($enrollment->event_series_id);
        Gate::forUser($actor)->authorize('update', $series);

        if ($enrollment->version !== $expectedVersion) {
            throw ValidationException::withMessages(['version' => 'The enrollment changed after it was loaded.']);
        }

        return $this->store($series, [
            'operation' => 'withdraw',
            'event_enrollment_id' => $enrollment->getKey(),
            'expected_version' => $expectedVersion,
        ], $this->activeOccurrences($series), [], $actor);
    }

    public function versions(EventSeries $series): array
    {
        $occurrences = $this->activeOccurrences($series);

        return [
            'series' => $series->version,
            'occurrences' => $occurrences->pluck('version', 'id')->map(fn ($version) => (int) $version)->sortKeys()->all(),
            'participants' => EventOccurrenceParticipant::query()
                ->whereIn('event_occurrence_id', $occurrences->pluck('id'))
                ->pluck('version', 'id')->map(fn ($version) => (int) $version)->sortKeys()->all(),
            'enrollments' => $series->enrollments()->pluck('version', 'id')->map(fn ($version) => (int) $version)->sortKeys()->all(),
        ];
    }

    private function store(EventSeries $series, array $command, Collection $occurrences, array $conflicts, User $actor): ScheduleChangePreview
    {
        $command = ScheduleCommand::canonical($command);
        $versions = ScheduleCommand::canonical($this->versions($series));
        $scope = ScheduleEditScope::Series;

        return ScheduleChangePreview::query()->create([
            'studio_id' => $series->studio_id,
            'actor_id' => $actor->getAuthIdentifier(),
            'event_series_id' => $series->getKey(),
            'command_type' => 'enrollment_change',
            'scope' => $scope,
            'command_hash' => SchedulePreviewEnvelope::hash(
                (string) $series->studio_id,
                $actor->getAuthIdentifier(),
                'enrollment_change',
                $scope->value,
                $versions,
                $command,
            ),
            'soft_warning_fingerprint' => SchedulePreviewEnvelope::softWarningFingerprint($conflicts),
            'command' => $command,
            'aggregate_versions' => $versions,
            'impact' => [
                'affected_occurrences' => $occurrences->count(),
                'effects' => ['calendar_roster_changed', 'notifications_projected'],
            ],
            'conflicts' => $conflicts,
            'status' => $conflicts === [] ? SchedulePreviewStatus::Ready : SchedulePreviewStatus::Blocked,
            'soft_warnings_acknowledged' => false,
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    private function activeOccurrences(EventSeries $series): Collection
    {
        return $series->occurrences()
            ->whereIn('status', [EventOccurrenceStatus::Tentative, EventOccurrenceStatus::Scheduled])
            ->orderBy('recurrence_id_local')
            ->get();
    }
}
