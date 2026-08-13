<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'studio_id', 'lesson_note_attachment_id', 'object_kind', 'disk', 'object_key',
    'reason', 'status', 'failure_code', 'claimed_at', 'lease_expires_at', 'completed_at',
])]
final class LessonNoteAttachmentPurgeClaim extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'claimed_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
