<?php

namespace App\Policies;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\EventSeries;
use App\Models\StaffAccountLink;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use App\Support\Scheduling\SchedulingAccess;

final class EventSeriesPolicy
{
    public function viewAny(User $user, Studio $studio): bool
    {
        return app(SchedulingAccess::class)->canViewAny($user, $studio, EventSeries::class)
            || $this->isBilling($user, $studio->getKey());
    }

    public function view(User $user, EventSeries $series): bool
    {
        if (app(SchedulingAccess::class)->canManage($user, $series->studio_id)) {
            return true;
        }

        if ($this->isBilling($user, $series->studio_id)) {
            return true;
        }

        return $series->teachers()->whereIn('staff_profile_id', $this->staffIds($user, $series->studio_id))->exists();
    }

    public function create(User $user, Studio $studio): bool
    {
        return app(SchedulingAccess::class)->canManage($user, $studio);
    }

    public function update(User $user, EventSeries $series): bool
    {
        return app(SchedulingAccess::class)->canManage($user, $series->studio_id);
    }

    private function staffIds(User $user, string $studioId): array
    {
        return StaffAccountLink::query()
            ->where('studio_id', $studioId)
            ->where('active', true)
            ->whereHas('membership', fn ($query) => $query->where('user_id', $user->getAuthIdentifier())->where('status', 'active'))
            ->pluck('staff_profile_id')->all();
    }

    private function isBilling(User $user, string $studioId): bool
    {
        return StudioMembership::query()->where('studio_id', $studioId)
            ->where('user_id', $user->getAuthIdentifier())->where('status', MembershipStatus::Active)
            ->where('role', MembershipRole::Billing)->exists();
    }
}
