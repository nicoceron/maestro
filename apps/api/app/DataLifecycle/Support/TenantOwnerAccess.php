<?php

namespace App\DataLifecycle\Support;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\User;

final class TenantOwnerAccess
{
    public static function allows(User $user, Studio $studio): bool
    {
        return $user->studios()
            ->whereKey($studio->getKey())
            ->wherePivot('status', MembershipStatus::Active->value)
            ->wherePivot('role', MembershipRole::Owner->value)
            ->exists();
    }
}
