<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['studio_id', 'lesson_note_id', 'revision', 'title', 'body_html', 'reason', 'actor_id', 'created_at'])]
final class LessonNoteRevision extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Lesson note revisions are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Lesson note revisions are immutable.'));
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LessonNoteAttachment::class);
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
