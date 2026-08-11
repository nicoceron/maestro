<?php

namespace App\Models;

use App\Enums\MembershipRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'id',
    'studio_id',
    'lineage_id',
    'delivery_version',
    'previous_invitation_id',
    'superseded_by_id',
    'email_normalized',
    'role',
    'token_hash',
    'pending_key',
    'invited_by_id',
    'accepted_by_id',
    'expires_at',
    'accepted_at',
    'revoked_at',
    'superseded_at',
    'last_sent_at',
    'send_count',
    'token_redacted_at',
])]
#[Hidden(['token_hash', 'pending_key'])]
class StudioInvitation extends Model
{
    use HasFactory, HasUlids;

    /** @return BelongsTo<Studio, $this> */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_id');
    }

    /** @return BelongsTo<StudioInvitation, $this> */
    public function previousInvitation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_invitation_id');
    }

    /** @return BelongsTo<StudioInvitation, $this> */
    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    /** @return HasMany<StudioInvitationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(StudioInvitationDelivery::class, 'invitation_id');
    }

    /** @return HasOne<StudioInvitationDelivery, $this> */
    public function latestDelivery(): HasOne
    {
        return $this->hasOne(StudioInvitationDelivery::class, 'invitation_id')->latestOfMany('delivery_version');
    }

    public function status(): string
    {
        if ($this->accepted_at !== null) {
            return 'accepted';
        }

        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        if ($this->superseded_at !== null) {
            return 'superseded';
        }

        return $this->expires_at->isPast() ? 'expired' : 'pending';
    }

    public function isPending(): bool
    {
        return $this->status() === 'pending';
    }

    public function resendAvailableAt(): CarbonImmutable
    {
        $anchor = $this->last_sent_at ?? $this->created_at;

        return $anchor->toImmutable()->addSeconds(
            (int) config('services.invitations.resend_cooldown_seconds', 60),
        );
    }

    public function canBeResent(): bool
    {
        return in_array($this->status(), ['pending', 'expired'], true)
            && $this->resendAvailableAt()->isPast();
    }

    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'delivery_version' => 'integer',
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
            'last_sent_at' => 'immutable_datetime',
            'send_count' => 'integer',
            'token_redacted_at' => 'immutable_datetime',
        ];
    }
}
