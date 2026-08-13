<?php

namespace App\Models;

use App\Enums\EventAssignmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'event_occurrence_id', 'location_id', 'room_id', 'status', 'previous_status', 'busy_starts_at', 'busy_ends_at', 'version'])]
class EventOccurrenceRoom extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'status' => EventAssignmentStatus::class,
            'busy_starts_at' => 'immutable_datetime',
            'busy_ends_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
