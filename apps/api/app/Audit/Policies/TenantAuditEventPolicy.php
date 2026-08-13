<?php

namespace App\Audit\Policies;

use App\Models\Studio;
use App\Models\User;
use App\SupportAccess\SupportAccessPolicy;

final class TenantAuditEventPolicy
{
    public function __construct(private readonly SupportAccessPolicy $access) {}

    public function viewAny(User $user, Studio $studio): bool
    {
        return $this->access->viewTenantAudit($user, $studio);
    }
}
