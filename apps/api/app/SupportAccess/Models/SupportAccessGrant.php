<?php

namespace App\SupportAccess\Models;

use App\Models\Studio;
use App\Models\User;
use App\SupportAccess\GrantStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'studio_id', 'requested_by_user_id', 'approved_by_user_id', 'revoked_by_user_id', 'idempotency_key',
    'scopes', 'reason', 'status', 'starts_at', 'expires_at', 'approved_at',
    'rejected_at', 'revoked_at', 'decision_reason', 'version', 'request_id', 'correlation_id',
])]
final class SupportAccessGrant extends Model
{
    use HasUlids;

    protected $table = 'support_access_grants';

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(SupportAccessSession::class, 'grant_id');
    }

    public function isUsable(): bool
    {
        return $this->status === GrantStatus::Approved
            && $this->approved_at !== null
            && $this->revoked_at === null
            && $this->starts_at->lte(now())
            && $this->expires_at->gt(now());
    }

    protected function casts(): array
    {
        return [
            'scopes' => 'array', 'status' => GrantStatus::class, 'version' => 'integer',
            'starts_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
