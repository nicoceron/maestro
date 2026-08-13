<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'studio_id', 'lesson_note_id', 'lesson_note_attachment_id', 'actor_id',
    'idempotency_key', 'command_hash', 'created_at',
])]
final class LessonNoteAttachmentCommand extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Attachment commands are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Attachment commands are immutable.'));
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(LessonNoteAttachment::class, 'lesson_note_attachment_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
