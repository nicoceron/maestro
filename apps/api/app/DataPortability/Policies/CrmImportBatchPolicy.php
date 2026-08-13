<?php

namespace App\DataPortability\Policies;

use App\DataPortability\Models\CrmImportBatch;
use App\DataPortability\Support\CrmDataPortabilityAccess;
use App\Models\Studio;
use App\Models\User;

final class CrmImportBatchPolicy
{
    public function create(User $user, Studio $studio): bool
    {
        return CrmDataPortabilityAccess::allows($user, $studio);
    }

    public function view(User $user, CrmImportBatch $batch): bool
    {
        return (int) $batch->requested_by_id === (int) $user->getAuthIdentifier()
            && CrmDataPortabilityAccess::allows($user, $batch->studio_id);
    }

    public function update(User $user, CrmImportBatch $batch): bool
    {
        return $this->view($user, $batch);
    }
}
