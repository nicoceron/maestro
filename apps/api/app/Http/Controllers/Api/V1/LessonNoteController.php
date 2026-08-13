<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\CommitLessonNoteDelivery;
use App\Actions\Attendance\CreateLessonNote;
use App\Actions\Attendance\PreviewLessonNoteDelivery;
use App\Actions\Attendance\ReviseLessonNote;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreLessonNoteRequest;
use App\Http\Requests\Api\V1\UpdateLessonNoteRequest;
use App\Http\Resources\LessonNoteDeliveryIntentResource;
use App\Http\Resources\LessonNoteDeliveryPreviewResource;
use App\Http\Resources\LessonNoteResource;
use App\Models\EventOccurrence;
use App\Models\LessonNote;
use App\Models\LessonNoteDeliveryPreview;
use App\Models\Studio;
use App\Support\Attendance\AttendanceAccess;
use App\Support\Attendance\AttendanceIdempotency;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

final class LessonNoteController extends Controller
{
    public function index(Request $request, Studio $studio, string $occurrence, AttendanceAccess $access): AnonymousResourceCollection
    {
        $event = EventOccurrence::query()->where('studio_id', $studio->getKey())->findOrFail($occurrence);
        abort_if($access->isBilling($request->user(), $studio), 403);
        $notes = LessonNote::query()->where('studio_id', $studio->getKey())
            ->where('event_occurrence_id', $event->getKey())->with([
                'occurrence',
                'participantPerson',
                'currentRevisionRecord.attachments.latestScan',
                'currentRevisionRecord.attachments.retirement',
            ])
            ->orderBy('created_at')->orderBy('id');

        if (! $access->canViewPrivateCompliance($request->user(), (string) $studio->getKey())) {
            if ($access->canCreateNote($request->user(), $event)) {
                $notes->where(fn ($query) => $query->where('audience', '<>', 'author_private')
                    ->orWhere('author_user_id', $request->user()->getAuthIdentifier()));
            } else {
                $studentIds = $access->accessibleStudentPersonIds($request->user(), (string) $studio->getKey());
                $guardianIds = $access->accessibleGuardianStudentPersonIds($request->user(), (string) $studio->getKey(), 'learning');
                abort_if($studentIds === [] && $guardianIds === [], 403);
                $studentCanViewGroup = $event->participants()->whereIn('person_id', $studentIds)
                    ->whereIn('status', ['reserved', 'confirmed'])->exists();
                $guardianCanViewGroup = $event->participants()->whereIn('person_id', $guardianIds)
                    ->whereIn('status', ['reserved', 'confirmed'])->exists();
                abort_unless($studentCanViewGroup || $guardianCanViewGroup, 404);
                $notes->where(function ($query) use ($studentIds, $guardianIds, $studentCanViewGroup, $guardianCanViewGroup): void {
                    $query->where(fn ($student) => $student->where('audience', 'student')
                        ->where(function ($scope) use ($studentIds, $studentCanViewGroup): void {
                            $scope->whereIn('person_id', $studentIds);
                            if ($studentCanViewGroup) {
                                $scope->orWhere('scope', 'group');
                            }
                        }))
                        ->orWhere(fn ($guardian) => $guardian->where('audience', 'guardian')
                            ->where(function ($scope) use ($guardianIds, $guardianCanViewGroup): void {
                                $scope->whereIn('person_id', $guardianIds);
                                if ($guardianCanViewGroup) {
                                    $scope->orWhere('scope', 'group');
                                }
                            }));
                });
            }
        }

        return LessonNoteResource::collection($notes->paginate(100)->withQueryString());
    }

    public function store(
        StoreLessonNoteRequest $request,
        Studio $studio,
        string $occurrence,
        CreateLessonNote $create,
    ): LessonNoteResource {
        $event = EventOccurrence::query()->where('studio_id', $studio->getKey())->findOrFail($occurrence);

        return new LessonNoteResource($create->handle(
            $event,
            $request->validated(),
            $request->user(),
            app(AttendanceIdempotency::class)->key($request->header('Idempotency-Key')),
        ));
    }

    public function update(
        UpdateLessonNoteRequest $request,
        Studio $studio,
        string $note,
        ReviseLessonNote $revise,
    ): LessonNoteResource {
        $record = LessonNote::query()->where('studio_id', $studio->getKey())->findOrFail($note);

        return new LessonNoteResource($revise->handle(
            $record,
            $request->validated(),
            $request->user(),
            app(AttendanceIdempotency::class)->key($request->header('Idempotency-Key')),
        ));
    }

    public function previewDelivery(
        Request $request,
        Studio $studio,
        string $note,
        PreviewLessonNoteDelivery $preview,
    ): LessonNoteDeliveryPreviewResource {
        $record = LessonNote::query()->where('studio_id', $studio->getKey())->findOrFail($note);

        return new LessonNoteDeliveryPreviewResource($preview->handle($record, $request->user()));
    }

    public function commitDelivery(
        Request $request,
        Studio $studio,
        string $preview,
        CommitLessonNoteDelivery $commit,
    ): LessonNoteDeliveryIntentResource {
        $record = LessonNoteDeliveryPreview::query()
            ->where('studio_id', $studio->getKey())
            ->where('actor_id', $request->user()->getAuthIdentifier())
            ->findOrFail($preview);

        return new LessonNoteDeliveryIntentResource($commit->handle(
            $record,
            $this->idempotencyKey($request),
            $request->user(),
        ));
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A non-empty Idempotency-Key header of at most 100 characters is required.',
            ]);
        }

        return $key;
    }
}
