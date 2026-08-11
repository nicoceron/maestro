<?php

namespace App\Enums;

enum PortalPermission: string
{
    case Calendar = 'calendar';
    case Attendance = 'attendance';
    case Learning = 'learning';
    case Billing = 'billing';
    case Booking = 'booking';
    case Messages = 'messages';

    /** @return list<string> */
    public static function defaults(): array
    {
        return array_map(
            static fn (self $permission): string => $permission->value,
            self::cases(),
        );
    }
}
