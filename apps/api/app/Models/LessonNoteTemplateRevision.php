<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['studio_id', 'lesson_note_template_id', 'revision', 'name', 'audience', 'body_html', 'active', 'reason', 'actor_id', 'created_at'])]
final class LessonNoteTemplateRevision extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Lesson note template revisions are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Lesson note template revisions are immutable.'));
    }

    protected function casts(): array
    {
        return ['revision' => 'integer', 'active' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
