<?php

namespace App\Models;

use App\Enums\LessonNoteAudience;
use App\Enums\LessonNoteScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'studio_id', 'event_occurrence_id', 'event_occurrence_participant_id', 'person_id',
    'author_staff_profile_id', 'author_user_id', 'scope', 'audience', 'title', 'body_html',
    'current_revision', 'version',
])]
final class LessonNote extends Model
{
    use HasUlids;

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'event_occurrence_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(LessonNoteRevision::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(LessonNoteAttachment::class);
    }

    public function currentRevisionRecord(): HasOne
    {
        return $this->hasOne(LessonNoteRevision::class)->ofMany('revision', 'max');
    }

    public function participantPerson(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'person_id');
    }

    protected function casts(): array
    {
        return [
            'scope' => LessonNoteScope::class, 'audience' => LessonNoteAudience::class,
            'current_revision' => 'integer', 'version' => 'integer',
        ];
    }
}
