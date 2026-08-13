<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['studio_id', 'actor_id', 'operation', 'idempotency_key', 'command_hash', 'result_projection', 'created_at'])]
final class AttendanceDomainCommand extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['result_projection' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
