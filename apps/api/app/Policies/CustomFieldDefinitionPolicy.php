<?php

namespace App\Policies;

use App\Models\CustomFieldDefinition;
use App\Models\Studio;
use App\Models\User;

final class CustomFieldDefinitionPolicy
{
    public function __construct(private readonly InstrumentPolicy $catalog) {}

    public function viewAny(User $user, Studio $studio): bool
    {
        return $this->catalog->viewAny($user, $studio);
    }

    public function view(User $user, CustomFieldDefinition $definition): bool
    {
        return $this->catalog->canManageStudio($user, $definition->studio_id);
    }

    public function create(User $user, Studio $studio): bool
    {
        return $this->catalog->create($user, $studio);
    }

    public function update(User $user, CustomFieldDefinition $definition): bool
    {
        return $this->catalog->canManageStudio($user, $definition->studio_id);
    }

    public function delete(User $user, CustomFieldDefinition $definition): bool
    {
        return $this->catalog->canManageStudio($user, $definition->studio_id);
    }
}
