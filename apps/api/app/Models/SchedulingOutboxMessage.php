<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'topic', 'aggregate_type', 'aggregate_id', 'aggregate_version', 'dedupe_key', 'payload', 'available_at', 'processed_at', 'attempts'])]
class SchedulingOutboxMessage extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'aggregate_version' => 'integer',
            'payload' => 'array',
            'available_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }
}
