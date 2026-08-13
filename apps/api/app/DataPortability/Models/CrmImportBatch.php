<?php

namespace App\DataPortability\Models;

use App\DataPortability\Policies\CrmImportBatchPolicy;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'studio_id', 'requested_by_id', 'idempotency_key', 'request_fingerprint', 'status', 'active_command_id',
    'schema_name', 'schema_version', 'encoding', 'portable_formula_escaping', 'original_name',
    'quarantine_path', 'source_sha256', 'source_size', 'row_count', 'processed_rows',
    'created_rows', 'updated_rows', 'skipped_rows', 'conflicted_rows', 'failed_rows',
    'column_mapping', 'previewed_at', 'commit_started_at', 'completed_at', 'expires_at',
    'purged_at', 'error_code', 'failure_digest',
])]
#[UsePolicy(CrmImportBatchPolicy::class)]
final class CrmImportBatch extends Model
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

    public function rows(): HasMany
    {
        return $this->hasMany(CrmImportRow::class, 'import_batch_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(CrmImportCommand::class, 'import_batch_id');
    }

    protected function casts(): array
    {
        return [
            'portable_formula_escaping' => 'boolean',
            'version' => 'integer',
            'column_mapping' => 'array',
            'previewed_at' => 'immutable_datetime',
            'commit_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }
}
