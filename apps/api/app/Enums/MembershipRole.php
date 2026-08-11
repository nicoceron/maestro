<?php

namespace App\Enums;

enum MembershipRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Office = 'office';
    case Billing = 'billing';
    case Teacher = 'teacher';

    /** @return list<string> */
    public static function managementValues(): array
    {
        return [
            self::Owner->value,
            self::Administrator->value,
            self::Office->value,
            self::Billing->value,
        ];
    }
}
