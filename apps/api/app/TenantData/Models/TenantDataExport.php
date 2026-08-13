<?php

namespace App\TenantData\Models;

use App\Models\Studio;
use App\Models\User;
use App\TenantData\Enums\TenantDataExportStatus;
use App\TenantData\Policies\TenantDataExportPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UsePolicy(TenantDataExportPolicy::class)]
#[Fillable([
    'studio_id',
    'requested_by_id',
    'idempotency_key',
    'request_fingerprint',
    'include_media_inventory',
    'status',
    'format_version',
    'next_dataset_index',
    'completed_datasets',
    'manifest',
    'archive_path',
    'archive_ciphertext_sha256',
    'archive_size',
    'ready_at',
    'expires_at',
    'purged_at',
    'download_count',
    'last_downloaded_at',
    'error_code',
    'failure_digest',
])]
final class TenantDataExport extends Model
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

    protected function casts(): array
    {
        return [
            'include_media_inventory' => 'boolean',
            'status' => TenantDataExportStatus::class,
            'next_dataset_index' => 'integer',
            'completed_datasets' => 'array',
            'manifest' => 'array',
            'archive_size' => 'integer',
            'ready_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
            'download_count' => 'integer',
            'last_downloaded_at' => 'immutable_datetime',
        ];
    }
}
