<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'actor_id', 'idempotency_key', 'operation_hash', 'status', 'result_type', 'result_id', 'result_projection', 'completed_at'])]
class SchedulingCommandClaim extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'result_projection' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }
}
