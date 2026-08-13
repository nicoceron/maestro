<?php

namespace App\Actions\Attendance;

use App\Models\LessonNote;
use App\Models\LessonNoteRevision;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;
use App\Support\Attendance\AttendanceIdempotency;
use App\Support\Attendance\LessonNoteSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReviseLessonNote
{
    public function __construct(
        private readonly AttendanceAccess $access,
        private readonly LessonNoteSanitizer $sanitizer,
        private readonly AttendanceIdempotency $idempotency,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(LessonNote $note, array $attributes, User $actor, string $idempotencyKey): LessonNote
    {
        abort_unless($this->access->canReviseNote($actor, $note), 403);

        $key = $this->idempotency->key($idempotencyKey);
        $operation = 'lesson-note.revise';
        $hash = $this->idempotency->hash((string) $note->studio_id, $actor, $operation, [
            'note_id' => $note->getKey(), 'attributes' => $attributes,
        ]);

        return DB::transaction(function () use ($note, $attributes, $actor, $key, $operation, $hash): LessonNote {
            $replay = $this->idempotency->replay((string) $note->studio_id, $actor, $key, $operation, $hash);
            if ($replay !== null) {
                return (new LessonNote)->newFromBuilder($replay->result_projection['lesson_note']);
            }

            $locked = LessonNote::query()->lockForUpdate()->findOrFail($note->getKey());
            abort_unless($this->access->canReviseNote($actor, $locked), 403);
            if ($locked->version !== (int) $attributes['version']) {
                throw ValidationException::withMessages(['version' => 'The note changed after it was opened.']);
            }

            $body = array_key_exists('body_html', $attributes)
                ? $this->sanitizer->sanitize($attributes['body_html'])
                : $locked->body_html;
            if (trim(strip_tags($body)) === '') {
                throw ValidationException::withMessages(['body_html' => 'The note body must contain readable text.']);
            }

            $nextTitle = array_key_exists('title', $attributes)
                ? trim((string) $attributes['title']) ?: null
                : $locked->title;
            LessonNoteRevision::query()->create([
                'studio_id' => $locked->studio_id,
                'lesson_note_id' => $locked->getKey(),
                'revision' => $locked->current_revision + 1,
                'title' => $nextTitle,
                'body_html' => $body,
                'reason' => isset($attributes['reason']) ? trim($attributes['reason']) ?: null : null,
                'actor_id' => $actor->getAuthIdentifier(),
                'created_at' => now(),
            ]);
            $locked->title = $nextTitle;
            $locked->body_html = $body;
            $locked->current_revision++;
            $locked->version++;
            $locked->save();
            $this->idempotency->store((string) $locked->studio_id, $actor, $key, $operation, $hash, [
                'lesson_note' => $locked->getAttributes(),
            ]);

            return $locked->load('revisions');
        }, attempts: 3);
    }
}
