<?php

namespace App\DataPortability\Policies;

use App\DataPortability\Models\CrmPortableExport;
use App\DataPortability\Support\CrmDataPortabilityAccess;
use App\Models\Studio;
use App\Models\User;

final class CrmPortableExportPolicy
{
    public function create(User $user, Studio $studio): bool
    {
        return CrmDataPortabilityAccess::allows($user, $studio);
    }

    public function view(User $user, CrmPortableExport $export): bool
    {
        return (int) $export->requested_by_id === (int) $user->getAuthIdentifier()
            && CrmDataPortabilityAccess::allows($user, $export->studio_id);
    }

    public function download(User $user, CrmPortableExport $export): bool
    {
        return $this->view($user, $export);
    }
}
