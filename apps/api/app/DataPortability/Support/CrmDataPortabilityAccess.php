<?php

namespace App\DataPortability\Support;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\User;

final class CrmDataPortabilityAccess
{
    public static function allows(User $user, Studio|string $studio): bool
    {
        $studioId = $studio instanceof Studio ? $studio->getKey() : $studio;

        return $user->studios()
            ->whereKey($studioId)
            ->wherePivot('status', MembershipStatus::Active->value)
            ->wherePivotIn('role', [
                MembershipRole::Owner->value,
                MembershipRole::Administrator->value,
                MembershipRole::Office->value,
            ])->exists();
    }
}
