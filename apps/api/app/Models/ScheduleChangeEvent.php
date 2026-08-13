<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'event_series_id', 'event_occurrence_id', 'actor_id', 'event_type', 'idempotency_key', 'payload', 'occurred_at'])]
class ScheduleChangeEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
