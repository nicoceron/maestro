<?php

namespace App\Actions\Attendance;

use App\Exceptions\AttendanceConflict;
use App\Jobs\ScanLessonNoteAttachmentJob;
use App\Models\LessonNote;
use App\Models\LessonNoteAttachment;
use App\Models\LessonNoteAttachmentCommand;
use App\Models\LessonNoteRevision;
use App\Models\User;
use App\Support\Attachments\AttachmentTypePolicy;
use App\Support\Attendance\AttendanceAccess;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class UploadLessonNoteAttachment
{
    public function __construct(
        private readonly AttendanceAccess $access,
        private readonly AttachmentTypePolicy $types,
    ) {}

    public function handle(
        LessonNote $note,
        UploadedFile $file,
        int $noteVersion,
        User $actor,
        string $idempotencyKey,
    ): LessonNoteAttachment {
        abort_unless($this->access->canReviseNote($actor, $note), 403);
        $metadata = $this->types->inspect($file);
        $disk = (string) config('lesson-notes.attachments.disk');
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages([
                'idempotency_key' => 'A non-empty Idempotency-Key header of at most 100 characters is required.',
            ]);
        }
        $commandHash = hash('sha256', json_encode([
            'studio_id' => $note->studio_id,
            'note_id' => $note->getKey(),
            'note_version' => $noteVersion,
            'actor_id' => $actor->getAuthIdentifier(),
            'sha256' => $metadata['sha256'],
            'size_bytes' => $metadata['size_bytes'],
            'extension' => $metadata['extension'],
            'detected_mime' => $metadata['detected_mime'],
        ], JSON_THROW_ON_ERROR));
        $directory = "quarantine/{$note->studio_id}/{$note->getKey()}";
        $filename = bin2hex(random_bytes(20)).'.'.$metadata['extension'];
        $storedPath = $file->storeAs($directory, $filename, ['disk' => $disk]);
        if (! is_string($storedPath) || $storedPath === '') {
            throw ValidationException::withMessages(['file' => 'The attachment could not be stored safely.']);
        }

        try {
            $attachment = DB::transaction(function () use ($note, $noteVersion, $actor, $metadata, $disk, $storedPath, $idempotencyKey, $commandHash): LessonNoteAttachment {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
                        "lesson-note-attachment:{$note->studio_id}:{$actor->getAuthIdentifier()}:{$idempotencyKey}",
                    ]);
                }

                $replay = LessonNoteAttachmentCommand::query()
                    ->where('studio_id', $note->studio_id)
                    ->where('actor_id', $actor->getAuthIdentifier())
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()->first();
                if ($replay !== null) {
                    if (! hash_equals($replay->command_hash, $commandHash)) {
                        throw new AttendanceConflict(
                            'idempotency_key_reused',
                            'The Idempotency-Key was already used for another attachment upload.',
                        );
                    }

                    return $replay->attachment()->with(['latestScan', 'retirement'])->firstOrFail();
                }

                $locked = LessonNote::query()->where('studio_id', $note->studio_id)
                    ->lockForUpdate()->findOrFail($note->getKey());
                abort_unless($this->access->canReviseNote($actor, $locked), 403);
                if ($locked->version !== $noteVersion) {
                    throw ValidationException::withMessages(['note_version' => 'The note changed after it was opened.']);
                }

                $active = LessonNoteAttachment::query()
                    ->where('studio_id', $locked->studio_id)
                    ->where('lesson_note_id', $locked->getKey())
                    ->whereDoesntHave('retirement');
                if ((clone $active)->count() >= (int) config('lesson-notes.attachments.maximum_active_per_note')) {
                    throw ValidationException::withMessages(['file' => 'This note has reached its active attachment limit.']);
                }
                if (((int) (clone $active)->sum('size_bytes') + $metadata['size_bytes'])
                    > (int) config('lesson-notes.attachments.maximum_active_bytes_per_note')) {
                    throw ValidationException::withMessages(['file' => 'This note has reached its active attachment storage limit.']);
                }

                $revision = LessonNoteRevision::query()
                    ->where('studio_id', $locked->studio_id)
                    ->where('lesson_note_id', $locked->getKey())
                    ->where('revision', $locked->current_revision)
                    ->lockForUpdate()->firstOrFail();
                $attachment = LessonNoteAttachment::query()->create([
                    'studio_id' => $locked->studio_id,
                    'lesson_note_id' => $locked->getKey(),
                    'lesson_note_revision_id' => $revision->getKey(),
                    'uploaded_by_user_id' => $actor->getAuthIdentifier(),
                    'quarantine_disk' => $disk,
                    'quarantine_key' => $storedPath,
                    ...$metadata,
                    'created_at' => now(),
                ]);

                LessonNoteAttachmentCommand::query()->create([
                    'studio_id' => $locked->studio_id,
                    'lesson_note_id' => $locked->getKey(),
                    'lesson_note_attachment_id' => $attachment->getKey(),
                    'actor_id' => $actor->getAuthIdentifier(),
                    'idempotency_key' => $idempotencyKey,
                    'command_hash' => $commandHash,
                    'created_at' => now(),
                ]);

                ScanLessonNoteAttachmentJob::dispatch(
                    (string) $attachment->studio_id,
                    (string) $attachment->getKey(),
                );

                return $attachment->load(['latestScan', 'retirement']);
            }, attempts: 3);

            if ($attachment->quarantine_key !== $storedPath) {
                Storage::disk($disk)->delete($storedPath);
            }

            return $attachment;
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($storedPath);

            throw $exception;
        }
    }
}
