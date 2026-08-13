<?php

namespace App\DataLifecycle\Models;

use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'studio_id',
    'aggregate_type',
    'aggregate_id',
    'sequence',
    'event_type',
    'from_status',
    'to_status',
    'actor_id',
    'request_id',
    'request_ip_hash',
    'metadata',
    'occurred_at',
])]
final class TenantDataLifecycleEvent extends Model
{
    use HasUlids;

    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Tenant data lifecycle events are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Tenant data lifecycle events are immutable.'));
    }

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'sequence' => 'integer',
        ];
    }
}
