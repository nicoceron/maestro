<?php

namespace App\Actions\Attendance;

use App\Models\EventOccurrence;
use App\Models\EventOccurrenceParticipant;
use App\Models\LessonNote;
use App\Models\LessonNoteRevision;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;
use App\Support\Attendance\AttendanceIdempotency;
use App\Support\Attendance\LessonNoteSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateLessonNote
{
    public function __construct(
        private readonly AttendanceAccess $access,
        private readonly LessonNoteSanitizer $sanitizer,
        private readonly AttendanceIdempotency $idempotency,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(EventOccurrence $occurrence, array $attributes, User $actor, string $idempotencyKey): LessonNote
    {
        abort_unless($this->access->canCreateNote($actor, $occurrence), 403);
        $staffProfileId = $this->access->staffProfileId($actor, (string) $occurrence->studio_id);
        if ($staffProfileId === null && ! $this->access->canManage($actor, (string) $occurrence->studio_id)) {
            throw ValidationException::withMessages(['author' => 'A linked staff profile is required to author lesson notes.']);
        }

        $body = $this->sanitizer->sanitize($attributes['body_html']);
        if (trim(strip_tags($body)) === '') {
            throw ValidationException::withMessages(['body_html' => 'The note body must contain readable text.']);
        }

        $key = $this->idempotency->key($idempotencyKey);
        $operation = 'lesson-note.create';
        $command = [
            ...$attributes,
            'occurrence_id' => $occurrence->getKey(),
            'title' => isset($attributes['title']) ? trim($attributes['title']) ?: null : null,
            'body_html' => $body,
        ];
        $hash = $this->idempotency->hash((string) $occurrence->studio_id, $actor, $operation, $command);

        return DB::transaction(function () use ($occurrence, $attributes, $actor, $staffProfileId, $body, $key, $operation, $hash): LessonNote {
            $replay = $this->idempotency->replay((string) $occurrence->studio_id, $actor, $key, $operation, $hash);
            if ($replay !== null) {
                return (new LessonNote)->newFromBuilder($replay->result_projection['lesson_note']);
            }

            $lockedOccurrence = EventOccurrence::query()->lockForUpdate()->findOrFail($occurrence->getKey());
            abort_unless($this->access->canCreateNote($actor, $lockedOccurrence), 403);
            $participant = null;
            if ($attributes['scope'] === 'participant') {
                $participant = EventOccurrenceParticipant::query()
                    ->where('studio_id', $occurrence->studio_id)
                    ->where('event_occurrence_id', $occurrence->getKey())
                    ->lockForUpdate()->findOrFail($attributes['participant_id']);
                if (! in_array($participant->status->value, ['reserved', 'confirmed'], true)) {
                    throw ValidationException::withMessages(['participant_id' => 'Notes may target only an active participant.']);
                }
            }

            $note = LessonNote::query()->create([
                'studio_id' => $occurrence->studio_id,
                'event_occurrence_id' => $occurrence->getKey(),
                'event_occurrence_participant_id' => $participant?->getKey(),
                'person_id' => $participant?->person_id,
                'author_staff_profile_id' => $staffProfileId,
                'author_user_id' => $actor->getAuthIdentifier(),
                'scope' => $attributes['scope'],
                'audience' => $attributes['audience'],
                'title' => isset($attributes['title']) ? trim($attributes['title']) ?: null : null,
                'body_html' => $body,
            ]);
            $note->refresh();
            LessonNoteRevision::query()->create([
                'studio_id' => $note->studio_id,
                'lesson_note_id' => $note->getKey(),
                'revision' => 1,
                'title' => $note->title,
                'body_html' => $note->body_html,
                'actor_id' => $actor->getAuthIdentifier(),
                'created_at' => now(),
            ]);
            $this->idempotency->store((string) $note->studio_id, $actor, $key, $operation, $hash, [
                'lesson_note' => $note->getAttributes(),
            ]);

            return $note->load('revisions');
        }, attempts: 3);
    }
}
