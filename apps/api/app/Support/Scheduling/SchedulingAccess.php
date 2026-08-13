<?php

namespace App\Support\Scheduling;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Models\ProgramOfferingStaff;
use App\Models\StaffAccountLink;
use App\Models\StaffAvailabilityOverride;
use App\Models\StaffAvailabilityWindow;
use App\Models\StaffSchedulingProfile;
use App\Models\StaffTravelBuffer;
use App\Models\Studio;
use App\Models\StudioMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class SchedulingAccess
{
    /** @var list<class-string<Model>> */
    private const STAFF_RECORDS = [
        StaffSchedulingProfile::class,
        StaffAvailabilityWindow::class,
        StaffAvailabilityOverride::class,
        StaffTravelBuffer::class,
    ];

    public function canManage(User $user, Studio|string $studio): bool
    {
        return $this->membership($user, $studio)
            ->whereIn('role', [
                MembershipRole::Owner->value,
                MembershipRole::Administrator->value,
                MembershipRole::Office->value,
            ])->exists();
    }

    /** @param class-string<Model> $modelClass */
    public function canViewAny(User $user, Studio $studio, string $modelClass): bool
    {
        if ($modelClass === StaffAccountLink::class || $modelClass === ProgramOfferingStaff::class) {
            return $this->canManage($user, $studio);
        }

        if (in_array($modelClass, self::STAFF_RECORDS, true)) {
            return $this->canManage($user, $studio) || $this->membership($user, $studio)
                ->where('role', MembershipRole::Teacher->value)->exists();
        }

        return $this->canManage($user, $studio) || $this->membership($user, $studio)
            ->where('role', MembershipRole::Teacher->value)->exists();
    }

    public function canView(User $user, Model $record): bool
    {
        if ($this->canManage($user, (string) $record->studio_id)) {
            return true;
        }

        if (in_array($record::class, self::STAFF_RECORDS, true)) {
            return $this->ownsStaffProfile($user, (string) $record->studio_id, (string) $record->staff_profile_id);
        }

        return $record::class !== StaffAccountLink::class
            && $record::class !== ProgramOfferingStaff::class
            && $record->active === true
            && $this->membership($user, (string) $record->studio_id)
                ->where('role', MembershipRole::Teacher->value)->exists();
    }

    /** @param class-string<Model> $modelClass */
    public function canCreate(User $user, Studio $studio, string $modelClass): bool
    {
        return $this->canManage($user, $studio)
            || (in_array($modelClass, self::STAFF_RECORDS, true)
                && $this->membership($user, $studio)->where('role', MembershipRole::Teacher->value)->exists());
    }

    public function canUpdate(User $user, Model $record): bool
    {
        return $this->canManage($user, (string) $record->studio_id)
            || (in_array($record::class, self::STAFF_RECORDS, true)
                && $this->ownsStaffProfile($user, (string) $record->studio_id, (string) $record->staff_profile_id));
    }

    public function canMutateStaffProfile(User $user, Studio $studio, string $staffProfileId): bool
    {
        return $this->canManage($user, $studio)
            || $this->ownsStaffProfile($user, (string) $studio->getKey(), $staffProfileId);
    }

    /** @param class-string<Model> $modelClass */
    public function scopeVisible(Builder $query, User $user, Studio $studio, string $modelClass): Builder
    {
        if ($this->canManage($user, $studio)) {
            return $query;
        }

        if (in_array($modelClass, self::STAFF_RECORDS, true)) {
            return $query->whereIn('staff_profile_id', StaffAccountLink::query()
                ->select('staff_profile_id')
                ->where('studio_id', $studio->getKey())
                ->where('active', true)
                ->whereIn('studio_membership_id', $this->membership($user, $studio)->select('id')));
        }

        return $query->where('active', true);
    }

    private function ownsStaffProfile(User $user, string $studioId, string $staffProfileId): bool
    {
        return StaffAccountLink::query()
            ->where('studio_id', $studioId)
            ->where('staff_profile_id', $staffProfileId)
            ->where('active', true)
            ->whereIn('studio_membership_id', $this->membership($user, $studioId)->select('id'))
            ->exists();
    }

    private function membership(User $user, Studio|string $studio): Builder
    {
        return StudioMembership::query()
            ->where('studio_id', $studio instanceof Studio ? $studio->getKey() : $studio)
            ->where('user_id', $user->getAuthIdentifier())
            ->where('status', MembershipStatus::Active);
    }
}
