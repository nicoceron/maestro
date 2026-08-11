<?php

namespace App\Actions\Invitations;

use App\Jobs\DeliverStudioInvitation;
use App\Models\StudioInvitation;
use App\Models\StudioInvitationDelivery;
use App\Models\User;
use App\Support\Audit\InvitationAudit;
use Throwable;

final class InvitationDeliveryOutbox
{
    public function __construct(private readonly InvitationAudit $audit) {}

    public function createIntent(StudioInvitation $invitation): StudioInvitationDelivery
    {
        $delivery = StudioInvitationDelivery::query()->create([
            'studio_id' => $invitation->studio_id,
            'invitation_id' => $invitation->getKey(),
            'delivery_version' => $invitation->delivery_version,
            'status' => 'pending',
        ]);

        $this->audit->record($invitation, 'invitation.delivery_queued', metadata: [
            'delivery_version' => $invitation->delivery_version,
        ]);

        return $delivery;
    }

    public function dispatch(StudioInvitation $invitation): void
    {
        try {
            DeliverStudioInvitation::dispatch(
                (string) $invitation->getKey(),
                $invitation->delivery_version,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function suppressPending(
        StudioInvitation $invitation,
        string $reason,
        ?User $actor = null,
        bool $includeRequestMetadata = true,
    ): void {
        $delivery = StudioInvitationDelivery::query()
            ->where('invitation_id', $invitation->getKey())
            ->where('delivery_version', $invitation->delivery_version)
            ->lockForUpdate()
            ->first();

        if ($delivery === null || $delivery->status !== 'pending') {
            return;
        }

        $delivery->forceFill([
            'status' => 'suppressed',
            'suppressed_at' => now(),
            'suppression_reason' => $reason,
        ])->save();
        $this->audit->record($invitation, 'invitation.delivery_suppressed', $actor, [
            'delivery_version' => $delivery->delivery_version,
            'reason' => $reason,
        ], $includeRequestMetadata);
    }
}
