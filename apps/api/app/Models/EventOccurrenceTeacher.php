<?php

namespace App\Models;

use App\Enums\EventAssignmentRole;
use App\Enums\EventAssignmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'event_occurrence_id', 'staff_profile_id', 'role', 'status', 'previous_status', 'busy_starts_at', 'busy_ends_at', 'version'])]
class EventOccurrenceTeacher extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'role' => EventAssignmentRole::class,
            'status' => EventAssignmentStatus::class,
            'busy_starts_at' => 'immutable_datetime',
            'busy_ends_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
