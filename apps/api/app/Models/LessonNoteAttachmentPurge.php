<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'studio_id', 'lesson_note_attachment_id', 'object_kind', 'disk',
    'object_key', 'reason', 'purged_at',
])]
final class LessonNoteAttachmentPurge extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Attachment purge history is immutable.'));
        self::deleting(fn (): never => throw new LogicException('Attachment purge history is immutable.'));
    }

    protected function casts(): array
    {
        return ['purged_at' => 'immutable_datetime'];
    }
}
