<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\RecordAttendance;
use App\Actions\Attendance\RecordAttendanceBulk;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RecordAttendanceBulkRequest;
use App\Http\Requests\Api\V1\RecordAttendanceRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\EventOccurrenceResource;
use App\Models\AttendanceRecord;
use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\Studio;
use App\Support\Attendance\AttendanceAccess;
use App\Support\Attendance\AttendanceIdempotency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AttendanceController extends Controller
{
    public function index(Request $request, Studio $studio, string $occurrence, AttendanceAccess $access): AnonymousResourceCollection
    {
        $record = EventOccurrence::query()->where('studio_id', $studio->getKey())->findOrFail($occurrence);
        $query = AttendanceRecord::query()
            ->where('studio_id', $studio->getKey())->where('event_occurrence_id', $record->getKey());
        if (! $access->canReadAllAttendance($request->user(), $record)) {
            $personIds = array_values(array_unique([
                ...$access->accessibleStudentPersonIds($request->user(), (string) $studio->getKey()),
                ...$access->accessibleGuardianStudentPersonIds($request->user(), (string) $studio->getKey(), 'attendance'),
            ]));
            abort_if($personIds === [], 403);
            abort_unless($record->participants()->whereIn('person_id', $personIds)
                ->where(function ($query): void {
                    $query->whereIn('status', ['reserved', 'confirmed'])
                        ->orWhere(fn ($canceled) => $canceled->where('status', 'canceled')
                            ->whereIn('previous_status', ['reserved', 'confirmed'])
                            ->whereHas('attendance', fn ($attendance) => $attendance->where('outcome', 'teacher_cancelled')));
                })->exists(), 404);
            $query->whereIn('person_id', $personIds);
        }

        return AttendanceRecordResource::collection($query->with('corrections')
            ->orderBy('person_id')->orderBy('id')->paginate(100)->withQueryString());
    }

    public function overdue(Request $request, Studio $studio, AttendanceAccess $access): AnonymousResourceCollection
    {
        abort_unless($access->canManage($request->user(), $studio), 403);
        $validated = $request->validate(['before' => ['sometimes', 'date', 'before_or_equal:now']]);
        $before = $validated['before'] ?? now()->subHours(24);

        return EventOccurrenceResource::collection(EventOccurrence::query()
            ->where('studio_id', $studio->getKey())->where('ends_at', '<=', $before)
            ->whereIn('status', ['scheduled', 'completed'])
            ->whereHas('participants', fn ($query) => $query->whereIn('status', ['reserved', 'confirmed']))
            ->whereHas('participants', fn ($query) => $query->whereIn('status', ['reserved', 'confirmed'])->whereDoesntHave('attendance'))
            ->orderBy('ends_at')->orderBy('id')->paginate(100)->withQueryString());
    }

    public function store(
        RecordAttendanceRequest $request,
        Studio $studio,
        string $occurrence,
        string $participant,
        RecordAttendance $record,
    ): AttendanceRecordResource {
        $event = EventOccurrence::query()->where('studio_id', $studio->getKey())->findOrFail($occurrence);
        $subject = EventOccurrenceParticipant::query()
            ->where('studio_id', $studio->getKey())->where('event_occurrence_id', $event->getKey())
            ->findOrFail($participant);

        return new AttendanceRecordResource($record->handle(
            $event,
            $subject,
            $request->validated(),
            $request->user(),
            app(AttendanceIdempotency::class)->key($request->header('Idempotency-Key')),
        ));
    }

    public function bulk(
        RecordAttendanceBulkRequest $request,
        Studio $studio,
        string $occurrence,
        RecordAttendanceBulk $bulk,
    ): AnonymousResourceCollection {
        $event = EventOccurrence::query()->where('studio_id', $studio->getKey())->findOrFail($occurrence);

        return AttendanceRecordResource::collection($bulk->handle(
            $event,
            $request->validated('items'),
            $request->user(),
            app(AttendanceIdempotency::class)->key($request->header('Idempotency-Key')),
        ));
    }

    public function express(Request $request, Studio $studio, string $occurrence, RecordAttendanceBulk $bulk): AnonymousResourceCollection
    {
        $event = EventOccurrence::query()->where('studio_id', $studio->getKey())->findOrFail($occurrence);

        return AttendanceRecordResource::collection($bulk->expressPresent(
            $event,
            $request->user(),
            app(AttendanceIdempotency::class)->key($request->header('Idempotency-Key')),
        ));
    }
}
