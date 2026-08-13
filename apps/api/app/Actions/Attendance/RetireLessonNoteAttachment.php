<?php

namespace App\Actions\Attendance;

use App\Models\LessonNoteAttachment;
use App\Models\LessonNoteAttachmentRetirement;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;
use Illuminate\Support\Facades\DB;

final class RetireLessonNoteAttachment
{
    public function __construct(private readonly AttendanceAccess $access) {}

    public function handle(LessonNoteAttachment $attachment, string $reason, User $actor): LessonNoteAttachmentRetirement
    {
        abort_unless($this->access->canReviseNote($actor, $attachment->note), 403);

        return DB::transaction(function () use ($attachment, $reason, $actor): LessonNoteAttachmentRetirement {
            $locked = LessonNoteAttachment::query()->where('studio_id', $attachment->studio_id)
                ->lockForUpdate()->findOrFail($attachment->getKey());
            abort_unless($this->access->canReviseNote($actor, $locked->note), 403);

            return $locked->retirement()->firstOrCreate([], [
                'studio_id' => $locked->studio_id,
                'lesson_note_id' => $locked->lesson_note_id,
                'retired_by_user_id' => $actor->getAuthIdentifier(),
                'reason' => trim($reason),
                'retired_at' => now(),
            ]);
        }, attempts: 3);
    }
}
