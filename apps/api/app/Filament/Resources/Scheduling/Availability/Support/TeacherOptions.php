<?php

namespace App\Filament\Resources\Scheduling\Availability\Support;

use App\Models\StaffAccountLink;
use App\Models\StaffProfile;
use App\Models\Studio;
use App\Models\User;
use App\Support\Scheduling\SchedulingAccess;

final class TeacherOptions
{
    /** @return array<string, string> */
    public static function for(User $user, Studio $studio): array
    {
        $query = StaffProfile::query()
            ->where('studio_id', $studio->getKey())
            ->where('status', 'active')
            ->with('person')
            ->orderBy('id');

        if (! app(SchedulingAccess::class)->canManage($user, $studio)) {
            $query->whereIn('id', StaffAccountLink::query()
                ->select('staff_profile_id')
                ->where('studio_id', $studio->getKey())
                ->where('active', true)
                ->whereHas('membership', fn ($membership) => $membership
                    ->where('user_id', $user->getAuthIdentifier())
                    ->where('status', 'active')));
        }

        return $query->get()
            ->filter(fn (StaffProfile $profile): bool => in_array('teacher', array_map(
                static fn (mixed $role): string => $role instanceof \BackedEnum ? $role->value : (string) $role,
                $profile->roles,
            ), true))
            ->mapWithKeys(fn (StaffProfile $profile): array => [
                (string) $profile->getKey() => $profile->person->displayName(),
            ])->all();
    }
}
