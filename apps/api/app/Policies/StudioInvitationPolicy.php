<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioMembership;
use App\Models\User;

final class StudioInvitationPolicy
{
    public function viewAny(User $user, Studio $studio): bool
    {
        return $this->managerRole($user, $studio) !== null;
    }

    public function create(User $user, Studio $studio): bool
    {
        return $this->managerRole($user, $studio) !== null;
    }

    public function invite(User $user, Studio $studio, MembershipRole $role): bool
    {
        $managerRole = $this->managerRole($user, $studio);

        if ($managerRole === MembershipRole::Owner) {
            return $role !== MembershipRole::Owner;
        }

        return $managerRole === MembershipRole::Administrator
            && ! in_array($role, [MembershipRole::Owner, MembershipRole::Administrator], true);
    }

    public function revoke(User $user, StudioInvitation $invitation): bool
    {
        return $this->invite($user, $invitation->studio, $invitation->role);
    }

    private function managerRole(User $user, Studio $studio): ?MembershipRole
    {
        $role = StudioMembership::query()
            ->where('studio_id', $studio->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->whereIn('role', [MembershipRole::Owner, MembershipRole::Administrator])
            ->value('role');

        return is_string($role) ? MembershipRole::tryFrom($role) : $role;
    }
}
