<?php

namespace App\Models;

use App\Enums\EventEnrollmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'event_series_id', 'person_id', 'role', 'status', 'begins_recurrence_id_local', 'ends_recurrence_id_local', 'version'])]
class EventEnrollment extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['status' => EventEnrollmentStatus::class, 'version' => 'integer'];
    }
}
