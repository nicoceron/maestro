<?php

namespace App\Models;

use App\Enums\LessonNoteAttachmentScanStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'studio_id', 'lesson_note_attachment_id', 'attempt', 'status', 'engine',
    'engine_version', 'detail_code', 'clean_disk', 'clean_key', 'scanned_at',
])]
final class LessonNoteAttachmentScan extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Attachment scan history is immutable.'));
        self::deleting(fn (): never => throw new LogicException('Attachment scan history is immutable.'));
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(LessonNoteAttachment::class, 'lesson_note_attachment_id');
    }

    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => LessonNoteAttachmentScanStatus::class,
            'scanned_at' => 'immutable_datetime',
        ];
    }
}
