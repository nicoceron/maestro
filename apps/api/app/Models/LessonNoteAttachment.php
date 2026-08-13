<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

#[Fillable([
    'studio_id', 'lesson_note_id', 'lesson_note_revision_id', 'uploaded_by_user_id',
    'quarantine_disk', 'quarantine_key', 'original_name', 'extension', 'declared_mime',
    'detected_mime', 'size_bytes', 'sha256', 'created_at',
])]
final class LessonNoteAttachment extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Lesson note attachment metadata is immutable.'));
        self::deleting(fn (): never => throw new LogicException('Lesson note attachments must be retired, not deleted.'));
    }

    public function note(): BelongsTo
    {
        return $this->belongsTo(LessonNote::class, 'lesson_note_id');
    }

    public function revision(): BelongsTo
    {
        return $this->belongsTo(LessonNoteRevision::class, 'lesson_note_revision_id');
    }

    public function scans(): HasMany
    {
        return $this->hasMany(LessonNoteAttachmentScan::class);
    }

    public function latestScan(): HasOne
    {
        return $this->hasOne(LessonNoteAttachmentScan::class)->ofMany('attempt', 'max');
    }

    public function retirement(): HasOne
    {
        return $this->hasOne(LessonNoteAttachmentRetirement::class);
    }

    public function isCleanAndActive(): bool
    {
        $this->loadMissing(['latestScan', 'retirement']);

        return $this->retirement === null && $this->latestScan?->status->value === 'clean';
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
