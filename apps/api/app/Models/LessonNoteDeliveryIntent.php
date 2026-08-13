<?php

namespace App\Models;

use App\Enums\LessonNoteDeliveryStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'studio_id', 'lesson_note_id', 'delivery_preview_id', 'actor_id', 'idempotency_key',
    'recipient_hash', 'recipient_projection', 'note_version', 'status', 'committed_at',
])]
final class LessonNoteDeliveryIntent extends Model
{
    use HasUlids;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Lesson note delivery intents are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Lesson note delivery intents are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'recipient_projection' => 'array', 'note_version' => 'integer', 'status' => LessonNoteDeliveryStatus::class,
            'committed_at' => 'immutable_datetime',
        ];
    }
}
