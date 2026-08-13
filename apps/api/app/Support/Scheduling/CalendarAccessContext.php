<?php

namespace App\Support\Scheduling;

use App\Enums\MembershipRole;
use App\Models\EventOccurrence;
use App\Models\EventSeries;
use App\Models\StaffAccountLink;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Support\Collection;

final readonly class CalendarAccessContext
{
    /** @param Collection<int, string> $staffProfileIds */
    public function __construct(
        public string $studioId,
        public int $actorId,
        public MembershipRole $role,
        public Collection $staffProfileIds,
    ) {}

    public static function for(Studio $studio, User $actor): self
    {
        $membership = StudioMembership::query()
            ->where('studio_id', $studio->getKey())
            ->where('user_id', $actor->getAuthIdentifier())
            ->where('status', 'active')
            ->firstOrFail();
        $staffProfileIds = StaffAccountLink::query()
            ->where('studio_id', $studio->getKey())
            ->where('active', true)
            ->where('studio_membership_id', $membership->getKey())
            ->pluck('staff_profile_id');

        return new self(
            (string) $studio->getKey(),
            (int) $actor->getAuthIdentifier(),
            $membership->role,
            $staffProfileIds,
        );
    }

    public function canManage(): bool
    {
        return in_array($this->role, [MembershipRole::Owner, MembershipRole::Administrator, MembershipRole::Office], true);
    }

    public function isBilling(): bool
    {
        return $this->role === MembershipRole::Billing;
    }

    public function isAssignedTeacher(EventOccurrence $occurrence): bool
    {
        return $occurrence->teachers->contains(
            fn ($teacher): bool => $teacher->status->value === 'assigned'
                && $this->staffProfileIds->contains($teacher->staff_profile_id),
        );
    }

    public function canViewOperationalDetails(EventOccurrence $occurrence): bool
    {
        return $this->canManage() || $this->isAssignedTeacher($occurrence);
    }

    public function isAssignedToSeries(EventSeries $series): bool
    {
        return $series->teachers->contains(
            fn ($teacher): bool => $this->staffProfileIds->contains($teacher->staff_profile_id),
        );
    }

    public function canViewOnlineJoinUrl(EventOccurrence $occurrence): bool
    {
        return $this->canViewOperationalDetails($occurrence) && ! $this->isBilling();
    }
}
