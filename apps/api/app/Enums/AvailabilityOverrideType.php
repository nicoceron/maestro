<?php

namespace App\Enums;

enum AvailabilityOverrideType: string
{
    case Available = 'available';
    case TimeOff = 'time_off';
}
