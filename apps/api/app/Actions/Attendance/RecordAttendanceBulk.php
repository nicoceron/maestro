<?php

namespace App\Actions\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\AttendanceRecord;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;
use App\Support\Attendance\AttendanceIdempotency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordAttendanceBulk
{
    public function __construct(
        private readonly AttendanceAccess $access,
        private readonly RecordAttendance $record,
        private readonly AttendanceIdempotency $idempotency,
    ) {}

    /** @param list<array<string, mixed>> $items @return Collection<int, AttendanceRecord> */
    public function handle(EventOccurrence $occurrence, array $items, User $actor, string $idempotencyKey): Collection
    {
        abort_unless($this->access->canRecordAttendance($actor, $occurrence), 403);

        if (count($items) !== count(array_unique(array_column($items, 'participant_id')))) {
            throw ValidationException::withMessages(['items' => 'Each participant may appear only once.']);
        }

        $key = $this->idempotency->key($idempotencyKey);
        $operation = 'attendance.bulk';
        $hash = $this->idempotency->hash((string) $occurrence->studio_id, $actor, $operation, [
            'occurrence_id' => $occurrence->getKey(), 'items' => $items,
        ]);

        return DB::transaction(function () use ($occurrence, $items, $actor, $key, $operation, $hash): Collection {
            $replay = $this->idempotency->replay((string) $occurrence->studio_id, $actor, $key, $operation, $hash);
            if ($replay !== null) {
                return collect($replay->result_projection['attendance_records'])->map(function (array $entry): AttendanceRecord {
                    $snapshot = (new AttendanceRecord)->newFromBuilder($entry['attributes']);
                    $snapshot->setRelation('corrections', collect($entry['corrections'])->map(
                        fn (array $attributes): AttendanceCorrection => (new AttendanceCorrection)->newFromBuilder($attributes),
                    ));

                    return $snapshot;
                });
            }

            $lockedOccurrence = EventOccurrence::query()->lockForUpdate()->findOrFail($occurrence->getKey());
            abort_unless($this->access->canRecordAttendance($actor, $lockedOccurrence), 403);
            $participantIds = collect($items)->pluck('participant_id')->sort()->values();
            $participants = EventOccurrenceParticipant::query()
                ->where('studio_id', $lockedOccurrence->studio_id)
                ->where('event_occurrence_id', $lockedOccurrence->getKey())
                ->whereIn('id', $participantIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if ($participants->count() !== count($items)) {
                throw ValidationException::withMessages(['items' => 'Every participant must belong to this occurrence.']);
            }

            $records = collect($items)->map(fn (array $item): AttendanceRecord => $this->record->handle(
                $occurrence,
                $participants->get($item['participant_id']),
                collect($item)->except('participant_id')->all(),
                $actor,
            ));

            $this->idempotency->store((string) $occurrence->studio_id, $actor, $key, $operation, $hash, [
                'attendance_records' => $records->map(fn (AttendanceRecord $record): array => [
                    'attributes' => $record->getAttributes(),
                    'corrections' => $record->corrections->map->getAttributes()->all(),
                ])->all(),
            ]);

            return $records;
        }, attempts: 3);
    }

    /** @return Collection<int, AttendanceRecord> */
    public function expressPresent(EventOccurrence $occurrence, User $actor, string $idempotencyKey): Collection
    {
        $participants = EventOccurrenceParticipant::query()
            ->where('studio_id', $occurrence->studio_id)
            ->where('event_occurrence_id', $occurrence->getKey())
            ->whereIn('status', ['reserved', 'confirmed'])
            ->whereDoesntHave('attendance')
            ->orderBy('id')->get();

        return $this->handle($occurrence, $participants->map(fn ($participant): array => [
            'participant_id' => $participant->getKey(),
            'outcome' => 'present',
        ])->all(), $actor, $idempotencyKey);
    }
}
