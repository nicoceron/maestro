<?php

namespace App\Actions\Invitations;

use App\Enums\MembershipRole;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioMembership;
use App\Models\User;
use App\Notifications\StudioInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CreateStudioInvitation
{
    /**
     * @return array{invitation: StudioInvitation, token: string}
     *
     * @throws ValidationException
     */
    public function handle(Studio $studio, User $inviter, string $email, MembershipRole $role): array
    {
        $email = User::normalizeEmail($email);

        $result = DB::transaction(function () use ($studio, $inviter, $email, $role): array {
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
            }

            $token = Str::random(64);
            $invitation = StudioInvitation::query()->create([
                'studio_id' => $studio->getKey(),
                'email_normalized' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'pending_key' => $pendingKey,
                'invited_by_id' => $inviter->getKey(),
                'expires_at' => now()->addDays((int) config('services.invitations.expires_days')),
            ]);

            return ['invitation' => $invitation, 'token' => $token];
        });

        $result['invitation']->load('studio');
        Notification::route('mail', $result['invitation']->email_normalized)
            ->notify(new StudioInvitationNotification(
                $result['invitation']->getKey(),
                $result['token'],
            ));

        return $result;
    }
}
