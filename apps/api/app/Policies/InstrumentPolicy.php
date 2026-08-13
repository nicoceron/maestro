<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Instrument;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class InstrumentPolicy
{
    public function viewAny(User $user, Studio $studio): bool
    {
        return $this->canManageStudio($user, $studio);
    }

    public function view(User $user, Instrument $instrument): bool
    {
        return $this->canManageStudio($user, $instrument->studio_id);
    }

    public function create(User $user, Studio $studio): bool
    {
        return $this->canManageStudio($user, $studio);
    }

    public function update(User $user, Instrument $instrument): bool
    {
        return $this->canManageStudio($user, $instrument->studio_id);
    }

    public function delete(User $user, Instrument $instrument): bool
    {
        return $this->canManageStudio($user, $instrument->studio_id);
    }

    public function canManageStudio(User $user, Studio|string $studio): bool
    {
        return $this->membership($user, $studio)
            ->whereIn('role', [
                MembershipRole::Owner->value,
                MembershipRole::Administrator->value,
                MembershipRole::Office->value,
            ])->exists();
    }

    public function canViewStudio(User $user, Studio|string $studio): bool
    {
        return $this->canManageStudio($user, $studio);
    }

    private function membership(User $user, Studio|string $studio): Builder
    {
        return StudioMembership::query()
            ->where('studio_id', $studio instanceof Studio ? $studio->getKey() : $studio)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', MembershipStatus::Active);
    }
}
