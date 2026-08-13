<?php

namespace App\Models;

use App\Enums\EventOverrideType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'event_series_id', 'event_occurrence_id', 'recurrence_id_local', 'type', 'patch', 'reason', 'version'])]
class EventOccurrenceOverride extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return ['type' => EventOverrideType::class, 'patch' => 'array', 'version' => 'integer'];
    }
}
