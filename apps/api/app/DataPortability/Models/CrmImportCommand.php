<?php

namespace App\DataPortability\Models;

use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id', 'import_batch_id', 'requested_by_id', 'type', 'idempotency_key',
    'request_fingerprint', 'expected_version', 'status', 'response_snapshot',
    'error_code', 'started_at', 'completed_at',
])]
final class CrmImportCommand extends Model
{
    use HasUlids;

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CrmImportBatch::class, 'import_batch_id');
    }

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
            'expected_version' => 'integer',
            'response_snapshot' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
