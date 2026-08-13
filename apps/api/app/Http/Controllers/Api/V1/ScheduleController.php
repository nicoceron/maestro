<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Scheduling\CommitCreateEventSeries;
use App\Actions\Scheduling\CommitEventEnrollment;
use App\Actions\Scheduling\CommitScheduleChange;
use App\Actions\Scheduling\ManageSchedulingHold;
use App\Actions\Scheduling\PreviewCreateEventSeries;
use App\Actions\Scheduling\PreviewEventEnrollment;
use App\Actions\Scheduling\PreviewScheduleChange;
use App\Actions\Scheduling\QueryCalendarOccurrences;
use App\Actions\Scheduling\SearchAvailableSlots;
use App\Enums\EventEnrollmentStatus;
use App\Enums\ScheduleEditScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CommitSchedulePreviewRequest;
use App\Http\Requests\Api\V1\PreviewCloneEventSeriesRequest;
use App\Http\Requests\Api\V1\PreviewEventEnrollmentRequest;
use App\Http\Requests\Api\V1\PreviewEventSeriesRequest;
use App\Http\Requests\Api\V1\PreviewScheduleChangeRequest;
use App\Http\Requests\Api\V1\PreviewWithdrawEnrollmentRequest;
use App\Http\Resources\EventOccurrenceResource;
use App\Http\Resources\EventSeriesResource;
use App\Http\Resources\PublicEventOccurrenceResource;
use App\Http\Resources\ScheduleChangePreviewResource;
use App\Models\EventEnrollment;
use App\Models\EventOccurrence;
use App\Models\EventSeries;
use App\Models\Person;
use App\Models\ScheduleChangePreview;
use App\Models\Studio;
use App\Support\Scheduling\CalendarAccessContext;
use App\Support\Scheduling\RecurrenceSetTransformer;
use App\Support\Scheduling\SchedulingAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ScheduleController extends Controller
{
    public function publicCalendar(Request $request, Studio $studio): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'page_size' => ['sometimes', 'integer', 'between:1,500'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ]);
        $from = CarbonImmutable::parse($validated['from']);
        $to = CarbonImmutable::parse($validated['to']);

        if ($from->diffInDays($to) > 93) {
            throw ValidationException::withMessages(['to' => 'Calendar queries are limited to 93 days.']);
        }

        $occurrences = EventOccurrence::query()->with('series')
            ->where('studio_id', $studio->getKey())
            ->where('status', 'scheduled')->whereNull('hold_expires_at')
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->whereHas('series', fn ($query) => $query->where('visibility', 'public')->where('status', 'active'))
            ->orderBy('starts_at')->orderBy('id')->cursorPaginate((int) ($validated['page_size'] ?? 200))->withQueryString();

        return PublicEventOccurrenceResource::collection($occurrences);
    }

    public function calendar(Request $request, Studio $studio, QueryCalendarOccurrences $calendar): AnonymousResourceCollection
    {
        $context = CalendarAccessContext::for($studio, $request->user());
        $request->attributes->set(CalendarAccessContext::class, $context);

        return EventOccurrenceResource::collection($calendar->paginate($studio, $request->user(), $request->query->all(), $context));
    }

    public function previewCreate(PreviewEventSeriesRequest $request, Studio $studio, PreviewCreateEventSeries $preview): ScheduleChangePreviewResource
    {
        $attributes = $request->validated();
        $acknowledge = (bool) Arr::pull($attributes, 'acknowledge_soft_warnings', false);

        return new ScheduleChangePreviewResource($preview->handle($studio, $attributes, $acknowledge, $request->user()));
    }

    public function previewClone(
        PreviewCloneEventSeriesRequest $request,
        Studio $studio,
        string $series,
        PreviewCreateEventSeries $preview,
        RecurrenceSetTransformer $recurrences,
    ): ScheduleChangePreviewResource {
        $source = EventSeries::query()->where('studio_id', $studio->getKey())
            ->with(['teachers', 'rooms', 'equipmentRequirements'])->findOrFail($series);
        Gate::authorize('update', $source);
        $overrides = $request->validated();
        $expectedVersion = (int) Arr::pull($overrides, 'version');

        if ($source->version !== $expectedVersion) {
            throw ValidationException::withMessages(['version' => 'The event series changed after it was loaded.']);
        }

        $acknowledge = (bool) Arr::pull($overrides, 'acknowledge_soft_warnings', false);
        $translatedRecurrence = $recurrences->forClone($source, $overrides);
        $command = [
            'source_series_id' => $source->getKey(), 'service_id' => $source->service_id,
            'program_offering_id' => $source->program_offering_id, 'location_id' => $source->location_id,
            'pricing_staff_profile_id' => $source->pricing_staff_profile_id, 'kind' => $source->kind->value,
            'visibility' => $source->visibility->value, 'shared_description' => $source->shared_description,
            'internal_description' => $source->internal_description, 'timezone' => $source->timezone,
            'duration_minutes' => $source->duration_minutes, 'rrule' => $translatedRecurrence['rrule'],
            'rdates' => $translatedRecurrence['rdates'], 'exdates' => $translatedRecurrence['exdates'], 'capacity' => $source->capacity,
            'teachers' => $source->teachers->map(fn ($item): array => ['staff_profile_id' => $item->staff_profile_id, 'role' => $item->role->value])->all(),
            'room_ids' => $source->rooms->pluck('room_id')->all(),
            'equipment' => $source->equipmentRequirements->map(fn ($item): array => ['equipment_id' => $item->equipment_id, 'quantity' => $item->quantity])->all(),
            ...$overrides,
        ];

        return new ScheduleChangePreviewResource($preview->handle($studio, $command, $acknowledge, $request->user(), 'clone_event_series'));
    }

    public function commitCreate(CommitSchedulePreviewRequest $request, Studio $studio, string $preview, CommitCreateEventSeries $commit): JsonResponse
    {
        $record = ScheduleChangePreview::query()->where('studio_id', $studio->getKey())->findOrFail($preview);
        $series = $commit->handle($studio, $record, $this->idempotencyKey($request), $request->user());

        return (new EventSeriesResource($series->load('occurrences')))->response()->setStatusCode(201);
    }

    public function previewChange(PreviewScheduleChangeRequest $request, Studio $studio, string $occurrence, PreviewScheduleChange $preview): ScheduleChangePreviewResource
    {
        $record = EventOccurrence::query()->where('studio_id', $studio->getKey())->findOrFail($occurrence);
        $attributes = $request->validated();
        $scope = ScheduleEditScope::from(Arr::pull($attributes, 'scope'));
        $acknowledge = (bool) Arr::pull($attributes, 'acknowledge_soft_warnings', false);

        return new ScheduleChangePreviewResource($preview->handle($record, $scope, $attributes, $acknowledge, $request->user()));
    }

    public function previewCancel(PreviewScheduleChangeRequest $request, Studio $studio, string $occurrence, PreviewScheduleChange $preview): ScheduleChangePreviewResource
    {
        return $this->previewOperation($request, $studio, $occurrence, $preview, 'cancel');
    }

    public function previewRestore(PreviewScheduleChangeRequest $request, Studio $studio, string $occurrence, PreviewScheduleChange $preview): ScheduleChangePreviewResource
    {
        return $this->previewOperation($request, $studio, $occurrence, $preview, 'restore');
    }

    public function commitChange(CommitSchedulePreviewRequest $request, Studio $studio, string $preview, CommitScheduleChange $commit): EventSeriesResource
    {
        $record = ScheduleChangePreview::query()->where('studio_id', $studio->getKey())
            ->whereIn('command_type', ['reschedule', 'cancel', 'restore'])->findOrFail($preview);

        return new EventSeriesResource($commit->handle($record, $this->idempotencyKey($request), $request->user()));
    }

    public function showSeries(Request $request, Studio $studio, string $series): EventSeriesResource
    {
        $record = EventSeries::query()->where('studio_id', $studio->getKey())
            ->with(['teachers', 'rooms', 'equipmentRequirements', 'occurrences'])->findOrFail($series);
        Gate::authorize('view', $record);

        return new EventSeriesResource($record);
    }

    public function convertHold(Request $request, Studio $studio, string $series, ManageSchedulingHold $holds): EventSeriesResource
    {
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $record = EventSeries::query()->where('studio_id', $studio->getKey())->findOrFail($series);

        return new EventSeriesResource($holds->convert($record, $validated['version'], $this->idempotencyKey($request), $request->user()));
    }

    public function releaseHold(Request $request, Studio $studio, string $series, ManageSchedulingHold $holds): EventSeriesResource
    {
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $record = EventSeries::query()->where('studio_id', $studio->getKey())->findOrFail($series);

        return new EventSeriesResource($holds->release($record, $validated['version'], $this->idempotencyKey($request), $request->user()));
    }

    public function previewEnrollment(
        PreviewEventEnrollmentRequest $request,
        Studio $studio,
        string $series,
        PreviewEventEnrollment $previews,
    ): ScheduleChangePreviewResource {
        $validated = $request->validated();
        $record = EventSeries::query()->where('studio_id', $studio->getKey())->findOrFail($series);
        $person = Person::query()->where('studio_id', $studio->getKey())->findOrFail($validated['person_id']);

        return new ScheduleChangePreviewResource(
            $previews->enroll($record, $person, EventEnrollmentStatus::from($validated['status']), $request->user()),
        );
    }

    public function roster(Request $request, Studio $studio, string $series): JsonResponse
    {
        $record = EventSeries::query()->where('studio_id', $studio->getKey())->findOrFail($series);
        Gate::authorize('update', $record);
        $enrollments = EventEnrollment::query()->where('studio_id', $studio->getKey())
            ->where('event_series_id', $record->getKey())->orderBy('created_at')->get();

        return response()->json(['data' => $enrollments->map(fn (EventEnrollment $enrollment): array => [
            'id' => $enrollment->getKey(),
            'series_id' => $enrollment->event_series_id,
            'person_id' => $enrollment->person_id,
            'role' => $enrollment->role,
            'status' => $enrollment->status,
            'version' => $enrollment->version,
        ])->values()]);
    }

    public function previewWithdrawal(
        PreviewWithdrawEnrollmentRequest $request,
        Studio $studio,
        string $series,
        string $enrollment,
        PreviewEventEnrollment $previews,
    ): ScheduleChangePreviewResource {
        $record = EventEnrollment::query()->where('studio_id', $studio->getKey())->where('event_series_id', $series)->findOrFail($enrollment);

        return new ScheduleChangePreviewResource($previews->withdraw($record, (int) $request->validated('version'), $request->user()));
    }

    public function commitEnrollment(
        CommitSchedulePreviewRequest $request,
        Studio $studio,
        string $preview,
        CommitEventEnrollment $commit,
    ): JsonResponse {
        $record = ScheduleChangePreview::query()->where('studio_id', $studio->getKey())
            ->where('command_type', 'enrollment_change')->findOrFail($preview);
        $enrollment = $commit->handle($record, $this->idempotencyKey($request), $request->user());

        return response()->json(['data' => [
            'id' => $enrollment->getKey(), 'series_id' => $enrollment->event_series_id,
            'person_id' => $enrollment->person_id, 'status' => $enrollment->status, 'version' => $enrollment->version,
        ]]);
    }

    public function slotSearch(Request $request, Studio $studio, SearchAvailableSlots $search, SchedulingAccess $access): JsonResponse
    {
        abort_unless($access->canManage($request->user(), $studio), 403);
        $validated = $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after:from'],
            'duration_minutes' => ['required', 'integer', 'between:5,1440'],
            'capacity' => ['sometimes', 'integer', 'between:1,1000'],
            'step_minutes' => ['sometimes', 'in:5,10,15,30,60'], 'location_id' => ['nullable', 'ulid'],
            'staff_profile_ids' => ['sometimes', 'array', 'max:20'], 'staff_profile_ids.*' => ['ulid', 'distinct'],
            'room_ids' => ['sometimes', 'array', 'max:20'], 'room_ids.*' => ['ulid', 'distinct'],
            'equipment' => ['sometimes', 'array', 'max:50'],
            'equipment.*.equipment_id' => ['required', 'ulid', 'distinct'], 'equipment.*.quantity' => ['required', 'integer', 'between:1,1000'],
        ]);

        return response()->json(['data' => $search->handle($studio->getKey(), $validated)]);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'The Idempotency-Key header is required.']);
        }

        return $key;
    }

    private function previewOperation(PreviewScheduleChangeRequest $request, Studio $studio, string $occurrence, PreviewScheduleChange $preview, string $operation): ScheduleChangePreviewResource
    {
        $record = EventOccurrence::query()->where('studio_id', $studio->getKey())->findOrFail($occurrence);
        $attributes = $request->validated();
        $scope = ScheduleEditScope::from(Arr::pull($attributes, 'scope'));
        $acknowledge = (bool) Arr::pull($attributes, 'acknowledge_soft_warnings', false);

        return new ScheduleChangePreviewResource($preview->handle($record, $scope, $attributes, $acknowledge, $request->user(), $operation));
    }
}
