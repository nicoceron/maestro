<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioInvitation;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

final class StudioInvitationPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

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

    public function resend(User $user, StudioInvitation $invitation): bool
    {
        return $this->invite($user, $invitation->studio, $invitation->role);
    }

    /** @return list<string> */
    public function invitableRoles(User $user, Studio $studio): array
    {
        return array_values(array_map(
            static fn (MembershipRole $role): string => $role->value,
            array_filter(
                MembershipRole::cases(),
                fn (MembershipRole $role): bool => $this->invite($user, $studio, $role),
            ),
        ));
    }

    private function managerRole(User $user, Studio $studio): ?MembershipRole
    {
        if ($this->tenantContext->hasStudio()
            && $this->tenantContext->studio()->is($studio)
            && $this->tenantContext->membership()->user_id === $user->getKey()) {
            $role = $this->tenantContext->membership()->role;

            return in_array($role, [MembershipRole::Owner, MembershipRole::Administrator], true)
                ? $role
                : null;
        }

        $role = StudioMembership::query()
            ->where('studio_id', $studio->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', MembershipStatus::Active)
            ->whereIn('role', [MembershipRole::Owner, MembershipRole::Administrator])
            ->value('role');

        return is_string($role) ? MembershipRole::tryFrom($role) : $role;
    }
}
