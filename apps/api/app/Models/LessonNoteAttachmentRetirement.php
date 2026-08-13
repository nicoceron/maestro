<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['studio_id', 'lesson_note_id', 'lesson_note_attachment_id', 'retired_by_user_id', 'reason', 'retired_at'])]
final class LessonNoteAttachmentRetirement extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Attachment retirement history is immutable.'));
        self::deleting(fn (): never => throw new LogicException('Attachment retirement history is immutable.'));
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(LessonNoteAttachment::class, 'lesson_note_attachment_id');
    }

    protected function casts(): array
    {
        return ['retired_at' => 'immutable_datetime'];
    }
}
