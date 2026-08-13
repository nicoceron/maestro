<?php

namespace App\Actions\Attendance;

use App\Models\LessonNote;
use App\Models\LessonNoteDeliveryPreview;
use App\Models\User;
use App\Support\Attachments\LessonNoteAttachmentProjection;
use App\Support\Attendance\AttendanceAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PreviewLessonNoteDelivery
{
    public function __construct(
        private readonly AttendanceAccess $access,
        private readonly LessonNoteAttachmentProjection $attachments,
    ) {}

    public function handle(LessonNote $note, User $actor): LessonNoteDeliveryPreview
    {
        abort_unless($this->access->canDeliverNote($actor, $note), 403);

        if ($note->audience->value === 'author_private') {
            throw ValidationException::withMessages(['audience' => 'Author-private notes cannot be delivered.']);
        }

        return DB::transaction(function () use ($note, $actor): LessonNoteDeliveryPreview {
            $locked = LessonNote::query()->lockForUpdate()->findOrFail($note->getKey());
            $recipientUserIds = $this->access->eligibleRecipientUserIds($locked);
            if ($recipientUserIds === []) {
                throw ValidationException::withMessages(['recipients' => 'No eligible recipients are linked to this note.']);
            }

            $attachmentProjection = $this->attachments->deliveryProjection($locked);
            $projection = [
                'user_ids' => $recipientUserIds,
                'recipient_count' => count($recipientUserIds),
                'attachments' => $attachmentProjection,
            ];
            $recipientHash = hash('sha256', json_encode($recipientUserIds, JSON_THROW_ON_ERROR));
            $attachmentHash = hash('sha256', json_encode($attachmentProjection, JSON_THROW_ON_ERROR));
            $commandHash = hash('sha256', json_encode([
                'studio_id' => $locked->studio_id,
                'note_id' => $locked->getKey(),
                'note_version' => $locked->version,
                'actor_id' => $actor->getAuthIdentifier(),
                'recipient_hash' => $recipientHash,
                'attachment_hash' => $attachmentHash,
            ], JSON_THROW_ON_ERROR));

            return LessonNoteDeliveryPreview::query()->create([
                'studio_id' => $locked->studio_id,
                'lesson_note_id' => $locked->getKey(),
                'actor_id' => $actor->getAuthIdentifier(),
                'note_version' => $locked->version,
                'command_hash' => $commandHash,
                'recipient_hash' => $recipientHash,
                'recipient_projection' => $projection,
                'expires_at' => now()->addMinutes(10),
            ]);
        }, attempts: 3);
    }
}
