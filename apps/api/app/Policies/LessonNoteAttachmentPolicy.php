<?php

namespace App\Policies;

use App\Enums\LessonNoteAttachmentScanStatus;
use App\Models\LessonNoteAttachment;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;

final class LessonNoteAttachmentPolicy
{
    public function __construct(private readonly AttendanceAccess $access) {}

    public function view(User $user, LessonNoteAttachment $attachment): bool
    {
        return $this->access->canViewNote($user, $attachment->note);
    }

    public function download(User $user, LessonNoteAttachment $attachment): bool
    {
        if (! $attachment->isCleanAndActive() || ! $this->view($user, $attachment)) {
            return false;
        }

        $isCurrent = $attachment->revision->revision === $attachment->note->current_revision;

        return $isCurrent || $this->access->canReviseNote($user, $attachment->note);
    }

    public function retire(User $user, LessonNoteAttachment $attachment): bool
    {
        return $this->access->canReviseNote($user, $attachment->note);
    }

    public function rescan(User $user, LessonNoteAttachment $attachment): bool
    {
        $attachment->loadMissing(['latestScan', 'retirement']);

        return $attachment->retirement === null
            && ($attachment->latestScan === null || $attachment->latestScan->status === LessonNoteAttachmentScanStatus::Failed)
            && $this->access->canReviseNote($user, $attachment->note);
    }
}
