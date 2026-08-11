<?php

namespace App\Enums;

enum StudioStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Closed = 'closed';
}
