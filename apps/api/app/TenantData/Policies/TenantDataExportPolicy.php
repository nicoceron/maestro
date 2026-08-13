<?php

namespace App\TenantData\Policies;

use App\DataLifecycle\Support\TenantOwnerAccess;
use App\Models\Studio;
use App\Models\User;
use App\TenantData\Models\TenantDataExport;

final class TenantDataExportPolicy
{
    public function viewAny(User $user, Studio $studio): bool
    {
        return TenantOwnerAccess::allows($user, $studio);
    }

    public function create(User $user, Studio $studio): bool
    {
        return TenantOwnerAccess::allows($user, $studio);
    }

    public function view(User $user, TenantDataExport $export): bool
    {
        return TenantOwnerAccess::allows($user, $export->studio);
    }

    public function download(User $user, TenantDataExport $export): bool
    {
        return $this->view($user, $export);
    }

    public function runRestoreDrill(User $user, TenantDataExport $export): bool
    {
        return $this->view($user, $export);
    }

    public function delete(User $user, TenantDataExport $export): bool
    {
        return false;
    }
}
