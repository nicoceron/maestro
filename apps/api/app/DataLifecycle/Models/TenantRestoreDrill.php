<?php

namespace App\DataLifecycle\Models;

use App\DataLifecycle\Enums\TenantRestoreDrillStatus;
use App\DataLifecycle\Policies\TenantRestoreDrillPolicy;
use App\Models\Studio;
use App\Models\User;
use App\TenantData\Models\TenantDataExport;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UsePolicy(TenantRestoreDrillPolicy::class)]
#[Fillable([
    'studio_id',
    'export_id',
    'requested_by_id',
    'status',
    'dry_run',
    'target_studio_id',
    'tenant_id_remap',
    'verification',
    'error_code',
    'failure_digest',
    'started_at',
    'completed_at',
])]
final class TenantRestoreDrill extends Model
{
    use HasUlids;

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<TenantDataExport, $this> */
    public function export(): BelongsTo
    {
        return $this->belongsTo(TenantDataExport::class, 'export_id');
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    protected function casts(): array
    {
        return [
            'status' => TenantRestoreDrillStatus::class,
            'dry_run' => 'boolean',
            'tenant_id_remap' => 'array',
            'verification' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
