<?php

namespace App\Enums;

enum StaffStatus: string
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Former = 'former';
}
