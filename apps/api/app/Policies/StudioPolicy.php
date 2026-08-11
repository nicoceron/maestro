<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\User;

class StudioPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Studio $studio): bool
    {
        return $user->studios()
            ->whereKey($studio->getKey())
            ->wherePivot('status', MembershipStatus::Active->value)
            ->exists();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Studio $studio): bool
    {
        return $user->studios()
            ->whereKey($studio->getKey())
            ->wherePivot('status', MembershipStatus::Active->value)
            ->wherePivotIn('role', MembershipRole::managementValues())
            ->exists();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Studio $studio): bool
    {
        return $user->studios()
            ->whereKey($studio->getKey())
            ->wherePivot('status', MembershipStatus::Active->value)
            ->wherePivot('role', MembershipRole::Owner->value)
            ->exists();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Studio $studio): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Studio $studio): bool
    {
        return false;
    }
}
