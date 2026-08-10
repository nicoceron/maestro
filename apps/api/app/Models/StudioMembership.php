<?php

namespace App\Models;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use Database\Factories\StudioMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable([
    'studio_id',
    'user_id',
    'role',
    'status',
    'job_title',
    'joined_at',
    'last_active_at',
    'preferences',
])]
class StudioMembership extends Pivot
{
    /** @use HasFactory<StudioMembershipFactory> */
    use HasFactory, HasUlids;

    public $incrementing = false;

    protected $table = 'studio_memberships';

    protected $keyType = 'string';

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => MembershipStatus::class,
            'joined_at' => 'immutable_datetime',
            'last_active_at' => 'immutable_datetime',
            'preferences' => 'array',
        ];
    }
}
