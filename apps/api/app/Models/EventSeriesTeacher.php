<?php

namespace App\Models;

use App\Enums\EventAssignmentRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'event_series_id', 'staff_profile_id', 'role'])]
class EventSeriesTeacher extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['role' => EventAssignmentRole::class];
    }
}
