<?php

namespace App\Actions\Invitations;

use App\Enums\MembershipStatus;
use App\Models\StudioInvitation;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\RequestDatabaseContext;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptStudioInvitation
{
    public function __construct(private readonly RequestDatabaseContext $databaseContext) {}

    /** @throws ValidationException */
    public function handle(User $user, string $token): StudioMembership
    {
        return DB::transaction(function () use ($user, $token): StudioMembership {
            $this->databaseContext->activateInvitationToken($token);
            $invitation = StudioInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->email_normalized !== User::normalizeEmail($user->email)) {
                $this->fail();
            }

            $membership = StudioMembership::query()
                ->where('studio_id', $invitation->studio_id)
                ->where('user_id', $user->getKey())
                ->first();

            if ($invitation->accepted_at !== null) {
                if ($invitation->accepted_by_id === $user->getKey()
                    && $membership?->status === MembershipStatus::Active) {
                    return $membership;
                }

                $this->fail();
            }

            if (! $invitation->isPending()) {
                $this->fail();
            }

            if ($membership !== null && $membership->status !== MembershipStatus::Active) {
                $this->fail();
            }

            $membership ??= StudioMembership::query()->create([
                'studio_id' => $invitation->studio_id,
                'user_id' => $user->getKey(),
                'role' => $invitation->role,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
                'preferences' => [],
            ]);

            $invitation->forceFill([
                'accepted_by_id' => $user->getKey(),
                'accepted_at' => now(),
                'pending_key' => null,
            ])->save();

            if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
                event(new Verified($user));
            }

            return $membership;
        });
    }

    /** @throws ValidationException */
    private function fail(): never
    {
        throw ValidationException::withMessages([
            'invitation_token' => ['This invitation cannot be accepted.'],
        ]);
    }
}
