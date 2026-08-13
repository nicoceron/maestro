<?php

namespace App\Enums;

enum ScheduleEditScope: string
{
    case One = 'one';
    case Future = 'future';
    case Series = 'series';
}
