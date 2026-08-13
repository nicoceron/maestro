<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Support\Attendance\AttendanceAccess;

final class AttendanceRecordPolicy
{
    public function __construct(private readonly AttendanceAccess $access) {}

    public function view(User $user, AttendanceRecord $record): bool
    {
        return $this->access->canViewParticipant($user, $record->participant);
    }

    public function update(User $user, AttendanceRecord $record): bool
    {
        return $this->access->canRecordAttendance($user, $record->occurrence);
    }
}
