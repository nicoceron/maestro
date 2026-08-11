<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\Person;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Filament\Facades\Filament;

final class PersonPolicy
{
    /** @var list<MembershipRole> */
    private const EDIT_ROLES = [
        MembershipRole::Owner,
        MembershipRole::Administrator,
        MembershipRole::Office,
    ];

    /** @var list<MembershipRole> */
    private const VIEW_ROLES = [
        MembershipRole::Owner,
        MembershipRole::Administrator,
        MembershipRole::Office,
        MembershipRole::Billing,
    ];

    public function viewAny(User $user, ?Studio $studio = null): bool
    {
        $studio ??= $this->filamentStudio();

        return $studio !== null && $this->hasRole($user, $studio, self::VIEW_ROLES);
    }

    public function view(User $user, Person $person): bool
    {
        return $this->hasRole($user, $person->studio_id, self::VIEW_ROLES);
    }

    public function create(User $user, ?Studio $studio = null): bool
    {
        $studio ??= $this->filamentStudio();

        return $studio !== null && $this->hasRole($user, $studio, self::EDIT_ROLES);
    }

    public function update(User $user, Person $person): bool
    {
        return $this->hasRole($user, $person->studio_id, self::EDIT_ROLES);
    }

    public function delete(User $user, Person $person): bool
    {
        return $this->hasRole($user, $person->studio_id, [MembershipRole::Owner]);
    }

    /** @param list<MembershipRole> $roles */
    private function hasRole(User $user, Studio|string $studio, array $roles): bool
    {
        $studioId = $studio instanceof Studio ? $studio->getKey() : $studio;

        return StudioMembership::query()
            ->where('studio_id', $studioId)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', MembershipStatus::Active)
            ->whereIn('role', array_map(
                static fn (MembershipRole $role): string => $role->value,
                $roles,
            ))
            ->exists();
    }

    private function filamentStudio(): ?Studio
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Studio ? $tenant : null;
    }
}
