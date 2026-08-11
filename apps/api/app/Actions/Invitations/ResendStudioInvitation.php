<?php

namespace App\Actions\Invitations;

use App\Models\StudioInvitation;
use App\Models\User;
use App\Support\Audit\InvitationAudit;
use App\Support\Auth\InvitationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class ResendStudioInvitation
{
    public function __construct(
        private readonly InvitationToken $tokens,
        private readonly InvitationDeliveryOutbox $outbox,
        private readonly InvitationAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(StudioInvitation $invitation, User $actor): StudioInvitation
    {
        $replacement = DB::transaction(function () use ($invitation, $actor): StudioInvitation {
            $previous = StudioInvitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $previous->canBeResent()) {
                throw ValidationException::withMessages([
                    'invitation' => ['This invitation cannot be resent yet.'],
                ]);
            }

            $recentResends = StudioInvitation::query()
                ->where('studio_id', $previous->studio_id)
                ->where('lineage_id', $previous->lineage_id)
                ->whereNotNull('previous_invitation_id')
                ->where('created_at', '>=', now()->subDay())
                ->count();

            if ($recentResends >= 3) {
                throw new TooManyRequestsHttpException(
                    86400,
                    'This invitation has reached its daily resend limit.',
                );
            }

            $replacementId = (string) Str::ulid();
            $deliveryVersion = $previous->delivery_version + 1;

            $previous->forceFill([
                'pending_key' => null,
                'superseded_at' => now(),
            ])->save();

            $replacement = StudioInvitation::query()->create([
                'id' => $replacementId,
                'studio_id' => $previous->studio_id,
                'lineage_id' => $previous->lineage_id,
                'delivery_version' => $deliveryVersion,
                'previous_invitation_id' => $previous->getKey(),
                'email_normalized' => $previous->email_normalized,
                'role' => $previous->role,
                'token_hash' => $this->tokens->digestFor($replacementId, $deliveryVersion),
                'pending_key' => $previous->studio_id.'|'.$previous->email_normalized,
                'invited_by_id' => $actor->getKey(),
                'expires_at' => now()->addDays((int) config('services.invitations.expires_days')),
            ]);

            $previous->forceFill(['superseded_by_id' => $replacement->getKey()])->save();
            $this->outbox->suppressPending($previous, 'superseded', $actor);
            $this->audit->record($previous, 'invitation.superseded', $actor, [
                'delivery_version' => $previous->delivery_version,
                'replacement_invitation_id' => $replacement->getKey(),
            ]);

            $this->audit->record($replacement, 'invitation.resent', $actor, [
                'delivery_version' => $deliveryVersion,
                'previous_invitation_id' => $previous->getKey(),
            ]);
            $this->outbox->createIntent($replacement);

            return $replacement;
        });

        DB::afterCommit(fn () => $this->outbox->dispatch($replacement));

        return $replacement->refresh()->load(['latestDelivery', 'studio']);
    }
}
