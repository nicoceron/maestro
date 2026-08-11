<?php

namespace App\Actions\Invitations;

use App\Enums\MembershipRole;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Audit\InvitationAudit;
use App\Support\Auth\InvitationToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateStudioInvitation
{
    public function __construct(
        private readonly InvitationToken $tokens,
        private readonly InvitationDeliveryOutbox $outbox,
        private readonly InvitationAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(Studio $studio, User $inviter, string $email, MembershipRole $role): StudioInvitation
    {
        $email = User::normalizeEmail($email);

        $invitation = DB::transaction(function () use ($studio, $inviter, $email, $role): StudioInvitation {
            Studio::query()->whereKey($studio->getKey())->lockForUpdate()->firstOrFail();

            $isMember = StudioMembership::query()
                ->where('studio_id', $studio->getKey())
                ->whereHas('user', fn ($query) => $query->where('email', $email))
                ->exists();

            if ($isMember) {
                throw ValidationException::withMessages([
                    'email' => ['That person already has a membership in this studio.'],
                ]);
            }

            $pendingKey = $studio->getKey().'|'.$email;
            $existing = StudioInvitation::query()
                ->where('pending_key', $pendingKey)
                ->lockForUpdate()
                ->first();

            if ($existing?->isPending()) {
                throw ValidationException::withMessages([
                    'email' => ['A pending invitation already exists for this email.'],
                ]);
            }

            if ($existing !== null) {
                $existing->forceFill([
                    'pending_key' => null,
                    'revoked_at' => $existing->revoked_at ?? now(),
                ])->save();
                $this->outbox->suppressPending($existing, 'replaced', $inviter);
                $this->audit->record($existing, 'invitation.revoked', $inviter, ['reason' => 'replaced']);
            }

            $id = (string) Str::ulid();
            $deliveryVersion = 1;
            $invitation = StudioInvitation::query()->create([
                'id' => $id,
                'studio_id' => $studio->getKey(),
                'lineage_id' => $id,
                'delivery_version' => $deliveryVersion,
                'email_normalized' => $email,
                'role' => $role,
                'token_hash' => $this->tokens->digestFor($id, $deliveryVersion),
                'pending_key' => $pendingKey,
                'invited_by_id' => $inviter->getKey(),
                'expires_at' => now()->addDays((int) config('services.invitations.expires_days')),
            ]);

            $this->audit->record($invitation, 'invitation.created', $inviter, [
                'role' => $role->value,
                'delivery_version' => $deliveryVersion,
            ]);
            $this->outbox->createIntent($invitation);

            return $invitation;
        });

        DB::afterCommit(fn () => $this->outbox->dispatch($invitation));

        return $invitation->refresh()->load(['latestDelivery', 'studio']);
    }
}
