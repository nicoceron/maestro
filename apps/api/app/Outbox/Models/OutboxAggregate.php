<?php

namespace App\Outbox\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'aggregate_type', 'aggregate_id', 'last_sequence', 'updated_at'])]
final class OutboxAggregate extends Model
{
    public const CREATED_AT = null;

    public $incrementing = false;

    protected $table = 'transactional_outbox_aggregates';

    protected function casts(): array
    {
        return ['last_sequence' => 'integer', 'updated_at' => 'immutable_datetime'];
    }
}
