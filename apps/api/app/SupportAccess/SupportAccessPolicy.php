<?php

namespace App\SupportAccess;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\SupportAccess\Models\PlatformSupportOperator;
use App\SupportAccess\Models\SupportAccessGrant;

final class SupportAccessPolicy
{
    public function isOperator(User $user): bool
    {
        return PlatformSupportOperator::query()
            ->where('user_id', $user->getAuthIdentifier())->where('active', true)->exists();
    }

    public function manageStudio(User $user, Studio|string $studio): bool
    {
        return StudioMembership::query()
            ->where('studio_id', $studio instanceof Studio ? $studio->getKey() : $studio)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', MembershipStatus::Active)
            ->whereIn('role', [MembershipRole::Owner, MembershipRole::Administrator])
            ->exists();
    }

    public function viewTenantAudit(User $user, Studio $studio): bool
    {
        return $this->manageStudio($user, $studio);
    }

    public function viewOwnGrant(User $user, SupportAccessGrant $grant): bool
    {
        return $grant->requested_by_user_id === $user->getAuthIdentifier() && $this->isOperator($user);
    }
}
