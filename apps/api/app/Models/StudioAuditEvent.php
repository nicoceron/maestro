<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'studio_id',
    'event_type',
    'subject_type',
    'subject_id',
    'actor_id',
    'request_id',
    'request_ip_hash',
    'metadata',
    'occurred_at',
])]
final class StudioAuditEvent extends Model
{
    use HasUlids;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Studio audit events are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Studio audit events are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
