<?php

namespace App\Models;

use App\Enums\EventParticipantStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['studio_id', 'event_occurrence_id', 'event_series_id', 'event_enrollment_id', 'person_id', 'role', 'status', 'previous_status', 'blocks_conflicts', 'busy_starts_at', 'busy_ends_at', 'version'])]
class EventOccurrenceParticipant extends Model
{
    use HasUlids;

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'event_occurrence_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function attendance(): HasOne
    {
        return $this->hasOne(AttendanceRecord::class);
    }

    protected function casts(): array
    {
        return [
            'status' => EventParticipantStatus::class,
            'blocks_conflicts' => 'boolean',
            'busy_starts_at' => 'immutable_datetime',
            'busy_ends_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
