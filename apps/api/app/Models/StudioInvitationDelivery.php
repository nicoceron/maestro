<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'studio_id',
    'invitation_id',
    'delivery_version',
    'status',
    'sent_at',
    'suppressed_at',
    'suppression_reason',
    'redacted_at',
])]
final class StudioInvitationDelivery extends Model
{
    use HasUlids;

    /** @return BelongsTo<StudioInvitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(StudioInvitation::class, 'invitation_id');
    }

    protected function casts(): array
    {
        return [
            'delivery_version' => 'integer',
            'sent_at' => 'immutable_datetime',
            'suppressed_at' => 'immutable_datetime',
            'redacted_at' => 'immutable_datetime',
        ];
    }
}
