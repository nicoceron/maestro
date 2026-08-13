<?php

namespace App\DataLifecycle\Models;

use App\DataLifecycle\Enums\TenantDeletionStatus;
use App\DataLifecycle\Policies\TenantDeletionRequestPolicy;
use App\Models\Studio;
use App\Models\User;
use App\TenantData\Models\TenantDataExport;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UsePolicy(TenantDeletionRequestPolicy::class)]
#[Fillable([
    'studio_id',
    'requested_by_id',
    'approved_by_id',
    'export_id',
    'idempotency_key',
    'request_fingerprint',
    'status',
    'reason',
    'confirmation_phrase_digest',
    'cooling_off_ends_at',
    'approved_at',
    'suspended_at',
    'quarantined_at',
    'purge_eligible_at',
    'cancelled_at',
    'restored_at',
    'verification',
    'version',
])]
final class TenantDeletionRequest extends Model
{
    use HasUlids;

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /** @return BelongsTo<TenantDataExport, $this> */
    public function export(): BelongsTo
    {
        return $this->belongsTo(TenantDataExport::class, 'export_id');
    }

    protected function casts(): array
    {
        return [
            'status' => TenantDeletionStatus::class,
            'cooling_off_ends_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'quarantined_at' => 'immutable_datetime',
            'purge_eligible_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'restored_at' => 'immutable_datetime',
            'verification' => 'array',
            'version' => 'integer',
        ];
    }
}
