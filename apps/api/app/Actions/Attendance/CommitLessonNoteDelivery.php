<?php

namespace App\Actions\Attendance;

use App\Exceptions\AttendanceConflict;
use App\Models\LessonNote;
use App\Models\LessonNoteDeliveryIntent;
use App\Models\LessonNoteDeliveryPreview;
use App\Models\SchedulingOutboxMessage;
use App\Models\User;
use App\Support\Attachments\LessonNoteAttachmentProjection;
use App\Support\Attendance\AttendanceAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommitLessonNoteDelivery
{
    public function __construct(
        private readonly AttendanceAccess $access,
        private readonly LessonNoteAttachmentProjection $attachments,
    ) {}

    public function handle(LessonNoteDeliveryPreview $preview, string $idempotencyKey, User $actor): LessonNoteDeliveryIntent
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw ValidationException::withMessages(['idempotency_key' => 'The Idempotency-Key may not exceed 100 characters.']);
        }

        return DB::transaction(function () use ($preview, $idempotencyKey, $actor): LessonNoteDeliveryIntent {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [
                    "lesson-note-delivery:{$preview->studio_id}:{$actor->getAuthIdentifier()}:{$idempotencyKey}",
                ]);
            }

            $lockedPreview = LessonNoteDeliveryPreview::query()
                ->where('actor_id', $actor->getAuthIdentifier())
                ->lockForUpdate()->findOrFail($preview->getKey());
            $note = LessonNote::query()->lockForUpdate()->findOrFail($lockedPreview->lesson_note_id);
            abort_unless($this->access->canDeliverNote($actor, $note), 403);

            $replay = LessonNoteDeliveryIntent::query()
                ->where('studio_id', $preview->studio_id)
                ->where('actor_id', $actor->getAuthIdentifier())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first();
            if ($replay !== null) {
                if ($replay->delivery_preview_id !== $preview->getKey()) {
                    throw new AttendanceConflict('idempotency_key_reused', 'The Idempotency-Key was already used for another delivery.');
                }

                return $replay;
            }

            if ($lockedPreview->consumed_at !== null) {
                throw new AttendanceConflict('preview_consumed', 'This delivery preview was already consumed.');
            }
            if ($lockedPreview->expires_at->isPast()) {
                throw new AttendanceConflict('preview_expired', 'This delivery preview expired.');
            }
            if ($note->version !== $lockedPreview->note_version) {
                throw new AttendanceConflict('note_changed_after_preview', 'The note changed after delivery was previewed.');
            }

            $recipientIds = $this->access->eligibleRecipientUserIds($note);
            $recipientHash = hash('sha256', json_encode($recipientIds, JSON_THROW_ON_ERROR));
            $attachmentProjection = $this->attachments->deliveryProjection($note);
            $attachmentHash = hash('sha256', json_encode($attachmentProjection, JSON_THROW_ON_ERROR));
            $commandHash = hash('sha256', json_encode([
                'studio_id' => $note->studio_id,
                'note_id' => $note->getKey(),
                'note_version' => $note->version,
                'actor_id' => $actor->getAuthIdentifier(),
                'recipient_hash' => $recipientHash,
                'attachment_hash' => $attachmentHash,
            ], JSON_THROW_ON_ERROR));
            if (! hash_equals($lockedPreview->recipient_hash, $recipientHash)) {
                throw new AttendanceConflict('recipients_changed_after_preview', 'Delivery recipients changed after the preview was created.');
            }
            if (! hash_equals($lockedPreview->command_hash, $commandHash)) {
                throw new AttendanceConflict('attachments_changed_after_preview', 'Delivery attachments changed after the preview was created.');
            }

            $intent = LessonNoteDeliveryIntent::query()->create([
                'studio_id' => $note->studio_id,
                'lesson_note_id' => $note->getKey(),
                'delivery_preview_id' => $lockedPreview->getKey(),
                'actor_id' => $actor->getAuthIdentifier(),
                'idempotency_key' => $idempotencyKey,
                'recipient_hash' => $lockedPreview->recipient_hash,
                'recipient_projection' => $lockedPreview->recipient_projection,
                'note_version' => $note->version,
                'status' => 'committed',
                'committed_at' => now(),
            ]);
            SchedulingOutboxMessage::query()->create([
                'studio_id' => $note->studio_id,
                'topic' => 'lesson_note.delivery_requested',
                'aggregate_type' => 'lesson_note',
                'aggregate_id' => $note->getKey(),
                'aggregate_version' => $note->version,
                'dedupe_key' => "lesson-note-delivery:{$intent->getKey()}",
                'payload' => [
                    'delivery_intent_id' => $intent->getKey(),
                    'lesson_note_id' => $note->getKey(),
                    'recipient_user_ids' => $intent->recipient_projection['user_ids'],
                    'attachments' => $intent->recipient_projection['attachments'] ?? [],
                ],
                'available_at' => now(),
            ]);
            $lockedPreview->consumed_at = now();
            $lockedPreview->save();

            return $intent;
        }, attempts: 3);
    }
}
