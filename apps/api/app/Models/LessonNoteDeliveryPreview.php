<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'lesson_note_id', 'actor_id', 'note_version', 'command_hash', 'recipient_hash', 'recipient_projection', 'expires_at', 'consumed_at'])]
final class LessonNoteDeliveryPreview extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'note_version' => 'integer', 'recipient_projection' => 'array',
            'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime',
        ];
    }
}
