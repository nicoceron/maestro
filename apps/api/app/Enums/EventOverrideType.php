<?php

namespace App\Enums;

enum EventOverrideType: string
{
    case Modified = 'modified';
    case Canceled = 'canceled';
    case Restored = 'restored';
}
