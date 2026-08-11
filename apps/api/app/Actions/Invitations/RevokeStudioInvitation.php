<?php

namespace App\Actions\Invitations;

use App\Models\StudioInvitation;
use App\Models\User;
use App\Support\Audit\InvitationAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevokeStudioInvitation
{
    public function __construct(
        private readonly InvitationDeliveryOutbox $outbox,
        private readonly InvitationAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(StudioInvitation $invitation, User $actor): void
    {
        DB::transaction(function () use ($invitation, $actor): void {
            $locked = StudioInvitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($locked->status() === 'revoked') {
                return;
            }

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'invitation' => ['This invitation cannot be revoked.'],
                ]);
            }

            $locked->forceFill([
                'revoked_at' => now(),
                'pending_key' => null,
            ])->save();
            $this->outbox->suppressPending($locked, 'revoked', $actor);
            $this->audit->record($locked, 'invitation.revoked', $actor);
        });
    }
}
