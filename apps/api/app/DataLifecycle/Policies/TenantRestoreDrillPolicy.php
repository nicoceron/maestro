<?php

namespace App\DataLifecycle\Policies;

use App\DataLifecycle\Models\TenantRestoreDrill;
use App\DataLifecycle\Support\TenantOwnerAccess;
use App\Models\User;

final class TenantRestoreDrillPolicy
{
    public function view(User $user, TenantRestoreDrill $drill): bool
    {
        return TenantOwnerAccess::allows($user, $drill->studio);
    }

    public function delete(User $user, TenantRestoreDrill $drill): bool
    {
        return false;
    }
}
