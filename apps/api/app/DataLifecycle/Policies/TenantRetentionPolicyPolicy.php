<?php

namespace App\DataLifecycle\Policies;

use App\DataLifecycle\Models\TenantRetentionPolicy;
use App\DataLifecycle\Support\TenantOwnerAccess;
use App\Models\User;

final class TenantRetentionPolicyPolicy
{
    public function view(User $user, TenantRetentionPolicy $policy): bool
    {
        return TenantOwnerAccess::allows($user, $policy->studio);
    }

    public function update(User $user, TenantRetentionPolicy $policy): bool
    {
        return $this->view($user, $policy);
    }

    public function placeLegalHold(User $user, TenantRetentionPolicy $policy): bool
    {
        return $this->view($user, $policy);
    }

    public function releaseLegalHold(User $user, TenantRetentionPolicy $policy): bool
    {
        return $this->view($user, $policy);
    }
}
