<?php

namespace App\DataPortability\Models;

use App\DataPortability\Policies\CrmPortableExportPolicy;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id', 'requested_by_id', 'idempotency_key', 'request_fingerprint', 'status',
    'format_version', 'manifest', 'archive_path', 'archive_sha256', 'archive_size',
    'ready_at', 'expires_at', 'purged_at', 'download_count', 'error_code', 'failure_digest',
])]
#[UsePolicy(CrmPortableExportPolicy::class)]
final class CrmPortableExport extends Model
{
    use HasUlids;

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'version' => 'integer',
            'ready_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }
}
