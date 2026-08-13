<?php

namespace App\Policies;

use App\Models\Studio;
use App\Models\Tag;
use App\Models\User;

final class TagPolicy
{
    public function __construct(private readonly InstrumentPolicy $catalog) {}

    public function viewAny(User $user, Studio $studio): bool
    {
        return $this->catalog->viewAny($user, $studio);
    }

    public function view(User $user, Tag $tag): bool
    {
        return $this->catalog->canManageStudio($user, $tag->studio_id);
    }

    public function create(User $user, Studio $studio): bool
    {
        return $this->catalog->create($user, $studio);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $this->catalog->canManageStudio($user, $tag->studio_id);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $this->catalog->canManageStudio($user, $tag->studio_id);
    }
}
