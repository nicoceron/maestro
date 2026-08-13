<?php

namespace App\Support\Scheduling;

use App\Enums\ApprovalStatus;
use App\Enums\AvailabilityOverrideType;
use App\Models\StaffAvailabilityOverride;
use App\Models\StaffAvailabilityWindow;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;

final class ResolveStaffAvailability
{
    /** @return array{available: bool, enforcement: string, source: string} */
    public function resolve(StaffProfile $profile, CarbonImmutable $instant): array
    {
        $override = StaffAvailabilityOverride::query()
            ->where('studio_id', $profile->studio_id)
            ->where('staff_profile_id', $profile->getKey())
            ->where('active', true)
            ->where('approval_status', ApprovalStatus::Approved->value)
            ->where('starts_at', '<=', $instant)
            ->where('ends_at', '>', $instant)
            ->orderByRaw("CASE WHEN kind = 'time_off' THEN 1 ELSE 2 END")
            ->orderBy('id')
            ->first();

        if ($override !== null) {
            return [
                'available' => $override->kind === AvailabilityOverrideType::Available,
                'enforcement' => $override->enforcement->value,
                'source' => $override->kind === AvailabilityOverrideType::TimeOff
                    ? 'approved_time_off'
                    : 'approved_availability_override',
            ];
        }

        $profileTimezone = $profile->schedulingProfile?->timezone ?? $profile->studio->timezone;
        $local = $instant->setTimezone($profileTimezone);
        $window = StaffAvailabilityWindow::query()
            ->where('studio_id', $profile->studio_id)
            ->where('staff_profile_id', $profile->getKey())
            ->where('weekday', $local->dayOfWeekIso)
            ->where('active', true)
            ->where('start_time', '<=', $local->format('H:i:s'))
            ->where('end_time', '>', $local->format('H:i:s'))
            ->orderBy('id')
            ->first();

        return $window === null
            ? ['available' => false, 'enforcement' => 'hard', 'source' => 'outside_weekly_availability']
            : ['available' => true, 'enforcement' => $window->enforcement->value, 'source' => 'weekly_availability'];
    }
}
