<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Attendance\RetireLessonNoteAttachment;
use App\Actions\Attendance\UploadLessonNoteAttachment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RetireLessonNoteAttachmentRequest;
use App\Http\Requests\Api\V1\StoreLessonNoteAttachmentRequest;
use App\Http\Resources\LessonNoteAttachmentResource;
use App\Jobs\ScanLessonNoteAttachmentJob;
use App\Models\LessonNote;
use App\Models\LessonNoteAttachment;
use App\Models\Studio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class LessonNoteAttachmentController extends Controller
{
    public function store(
        StoreLessonNoteAttachmentRequest $request,
        Studio $studio,
        string $note,
        UploadLessonNoteAttachment $upload,
    ): JsonResponse {
        $record = LessonNote::query()->where('studio_id', $studio->getKey())->findOrFail($note);
        abort_if($request->user()->cannot('update', $record), 403);
        $attachment = $upload->handle(
            $record,
            $request->file('file'),
            (int) $request->validated('note_version'),
            $request->user(),
            (string) $request->header('Idempotency-Key'),
        );

        return (new LessonNoteAttachmentResource($attachment))
            ->response()->setStatusCode(202);
    }

    public function retryScan(Request $request, Studio $studio, string $note, string $attachment): JsonResponse
    {
        $record = $this->attachment($studio, $note, $attachment);
        abort_unless($request->user()->can('rescan', $record), 403);
        ScanLessonNoteAttachmentJob::dispatch((string) $studio->getKey(), (string) $record->getKey());

        return (new LessonNoteAttachmentResource($record->fresh(['latestScan', 'retirement'])))
            ->response()->setStatusCode(202);
    }

    public function retire(
        RetireLessonNoteAttachmentRequest $request,
        Studio $studio,
        string $note,
        string $attachment,
        RetireLessonNoteAttachment $retire,
    ): JsonResponse {
        $record = $this->attachment($studio, $note, $attachment);
        abort_unless($request->user()->can('retire', $record), 403);
        $retire->handle($record, $request->validated('reason'), $request->user());

        return response()->json(status: 204);
    }

    public function downloadUrl(Request $request, Studio $studio, string $note, string $attachment): JsonResponse
    {
        $record = $this->attachment($studio, $note, $attachment);
        abort_unless($request->user()->can('download', $record), 404);
        abort_unless($this->cleanObjectExists($record), 404);
        $minutes = min(15, max(1, (int) config('lesson-notes.attachments.download_url_minutes')));
        $expiresAt = now()->addMinutes($minutes);

        return response()->json(['data' => [
            'url' => URL::temporarySignedRoute('api.v1.lesson-note-attachments.download', $expiresAt, [
                'studio' => $studio->getRouteKey(),
                'note' => $record->lesson_note_id,
                'attachment' => $record->getKey(),
            ]),
            'expires_at' => $expiresAt->toAtomString(),
        ]]);
    }

    public function download(Request $request, Studio $studio, string $note, string $attachment): StreamedResponse
    {
        $record = $this->attachment($studio, $note, $attachment);
        abort_unless($request->user()->can('download', $record), 404);
        $scan = $record->latestScan;
        abort_unless($scan?->clean_disk !== null && $scan->clean_key !== null, 404);
        abort_unless($this->cleanObjectExists($record), 404);

        return Storage::disk($scan->clean_disk)->download($scan->clean_key, $record->original_name, [
            'Content-Type' => $record->detected_mime,
            'Content-Length' => (string) $record->size_bytes,
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => 'sandbox',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function attachment(Studio $studio, string $note, string $attachment): LessonNoteAttachment
    {
        return LessonNoteAttachment::query()
            ->where('studio_id', $studio->getKey())
            ->where('lesson_note_id', $note)
            ->with(['note.occurrence', 'revision', 'latestScan', 'retirement'])
            ->findOrFail($attachment);
    }

    private function cleanObjectExists(LessonNoteAttachment $attachment): bool
    {
        $scan = $attachment->latestScan;
        if ($scan?->clean_disk === null || $scan->clean_key === null) {
            return false;
        }

        try {
            return Storage::disk($scan->clean_disk)->exists($scan->clean_key);
        } catch (\Throwable) {
            return false;
        }
    }
}
