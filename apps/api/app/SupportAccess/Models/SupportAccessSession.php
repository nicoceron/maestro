<?php

namespace App\SupportAccess\Models;

use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id', 'grant_id', 'support_user_id', 'approved_by_user_id', 'scopes',
    'reason', 'token_hash', 'recent_auth_at', 'mfa_verified_at', 'started_at',
    'expires_at', 'ended_at', 'end_reason',
])]
#[Hidden(['token_hash'])]
final class SupportAccessSession extends Model
{
    use HasUlids;

    protected $table = 'support_access_sessions';

    public function grant(): BelongsTo
    {
        return $this->belongsTo(SupportAccessGrant::class, 'grant_id');
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function supportUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'support_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->gt(now()) && $this->grant->isUsable();
    }

    protected function casts(): array
    {
        return [
            'scopes' => 'array', 'recent_auth_at' => 'immutable_datetime',
            'mfa_verified_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime',
        ];
    }
}
