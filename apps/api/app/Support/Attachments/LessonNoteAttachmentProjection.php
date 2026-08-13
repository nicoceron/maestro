<?php

namespace App\Support\Attachments;

use App\Models\LessonNote;
use App\Models\LessonNoteAttachment;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;
use Illuminate\Support\Collection;

final class LessonNoteAttachmentProjection
{
    public function __construct(private readonly AttendanceAccess $access) {}

    /** @return Collection<int, LessonNoteAttachment> */
    public function visibleCurrentAttachments(LessonNote $note, User $viewer): Collection
    {
        abort_unless($this->access->canViewNote($viewer, $note), 403);

        $attachments = $this->currentAttachments($note);
        if ($this->access->canReviseNote($viewer, $note)) {
            return $attachments;
        }

        return $attachments->filter(
            fn (LessonNoteAttachment $attachment): bool => $attachment->isCleanAndActive(),
        )->values();
    }

    /** @return list<array{id: string, name: string, mime: string, size_bytes: int, sha256: string}> */
    public function deliveryProjection(LessonNote $note): array
    {
        return $this->currentAttachments($note)
            ->filter(fn (LessonNoteAttachment $attachment): bool => $attachment->isCleanAndActive())
            ->map(fn (LessonNoteAttachment $attachment): array => [
                'id' => (string) $attachment->getKey(),
                'name' => $attachment->original_name,
                'mime' => $attachment->detected_mime,
                'size_bytes' => $attachment->size_bytes,
                'sha256' => $attachment->sha256,
            ])->values()->all();
    }

    /** @return Collection<int, LessonNoteAttachment> */
    private function currentAttachments(LessonNote $note): Collection
    {
        $note->loadMissing([
            'currentRevisionRecord.attachments.latestScan',
            'currentRevisionRecord.attachments.retirement',
        ]);
        if ($note->currentRevisionRecord === null) {
            return collect();
        }

        return $note->currentRevisionRecord->attachments
            ->each(function (LessonNoteAttachment $attachment) use ($note): void {
                $attachment->setRelation('note', $note);
                $attachment->setRelation('revision', $note->currentRevisionRecord);
            })
            ->sortBy(fn (LessonNoteAttachment $attachment): string => $attachment->created_at->format('Y-m-d H:i:s.u').$attachment->getKey())
            ->values();
    }
}
