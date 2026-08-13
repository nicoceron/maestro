<?php

namespace App\Enums;

enum SchedulePreviewStatus: string
{
    case Ready = 'ready';
    case Blocked = 'blocked';
}
