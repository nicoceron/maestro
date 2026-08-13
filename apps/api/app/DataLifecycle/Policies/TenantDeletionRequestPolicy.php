<?php

namespace App\DataLifecycle\Policies;

use App\DataLifecycle\Models\TenantDeletionRequest;
use App\DataLifecycle\Support\TenantOwnerAccess;
use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Studio;
use App\Models\User;

final class TenantDeletionRequestPolicy
{
    public function create(User $user, Studio $studio): bool
    {
        return TenantOwnerAccess::allows($user, $studio);
    }

    public function view(User $user, TenantDeletionRequest $request): bool
    {
        return TenantOwnerAccess::allows($user, $request->studio);
    }

    public function approve(User $user, TenantDeletionRequest $request): bool
    {
        return $request->requested_by_id !== $user->getAuthIdentifier()
            && $user->studios()
                ->whereKey($request->studio_id)
                ->wherePivot('status', MembershipStatus::Active->value)
                ->wherePivot('role', MembershipRole::Administrator->value)
                ->exists();
    }

    public function cancel(User $user, TenantDeletionRequest $request): bool
    {
        return $this->view($user, $request);
    }

    public function restore(User $user, TenantDeletionRequest $request): bool
    {
        return $this->view($user, $request);
    }

    public function delete(User $user, TenantDeletionRequest $request): bool
    {
        return false;
    }
}
