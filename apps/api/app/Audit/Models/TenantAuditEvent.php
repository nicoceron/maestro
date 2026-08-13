<?php

namespace App\Audit\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable([
    'studio_id', 'stream_sequence', 'event_type', 'subject_type', 'subject_id',
    'actor_type', 'actor_user_id', 'actor_display', 'support_session_id',
    'request_id', 'correlation_id', 'causation_id', 'request_method',
    'request_ip_hash', 'user_agent_hash', 'payload_version', 'payload',
    'previous_hash', 'integrity_hash', 'occurred_at',
])]
final class TenantAuditEvent extends Model
{
    use HasUlids;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected $table = 'tenant_audit_events';

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Tenant audit events are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Tenant audit events are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'stream_sequence' => 'integer',
            'actor_user_id' => 'integer',
            'payload_version' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
