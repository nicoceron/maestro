<?php

namespace App\DataLifecycle\Models;

use App\DataLifecycle\Policies\TenantRetentionPolicyPolicy;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UsePolicy(TenantRetentionPolicyPolicy::class)]
#[Fillable([
    'studio_id',
    'export_ttl_hours',
    'deletion_cooling_off_days',
    'deletion_quarantine_days',
    'operational_retention_days',
    'media_retention_days',
    'audit_retention_days',
    'legal_hold',
    'legal_hold_reason',
    'legal_hold_placed_by_id',
    'legal_hold_placed_at',
    'legal_hold_released_by_id',
    'legal_hold_released_at',
    'version',
])]
final class TenantRetentionPolicy extends Model
{
    use HasUlids;

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<User, $this> */
    public function legalHoldPlacedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legal_hold_placed_by_id');
    }

    protected function casts(): array
    {
        return [
            'legal_hold' => 'boolean',
            'legal_hold_placed_at' => 'immutable_datetime',
            'legal_hold_released_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }
}
