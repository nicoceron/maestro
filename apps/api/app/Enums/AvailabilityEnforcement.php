<?php

namespace App\Enums;

enum AvailabilityEnforcement: string
{
    case Hard = 'hard';
    case Soft = 'soft';
}
