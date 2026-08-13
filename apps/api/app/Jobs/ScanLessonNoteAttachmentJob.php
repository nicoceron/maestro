<?php

namespace App\Jobs;

use App\Actions\Attendance\ScanLessonNoteAttachment;
use App\Models\LessonNoteAttachment;
use App\Models\Studio;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ScanLessonNoteAttachmentJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public function __construct(
        public readonly string $studioId,
        public readonly string $attachmentId,
    ) {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->studioId.':'.$this->attachmentId;
    }

    public function handle(ScanLessonNoteAttachment $scan, RequestDatabaseContext $context): void
    {
        $studio = Studio::query()->findOrFail($this->studioId);
        try {
            $context->activateStudioIdForSession((string) $studio->getKey());
            $attachment = LessonNoteAttachment::query()
                ->where('studio_id', $studio->getKey())->findOrFail($this->attachmentId);
            $scan->handle($attachment);
        } finally {
            $context->clearStudio();
        }
    }
}
