<?php

namespace App\Enums;

enum EventSeriesStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
    case Canceled = 'canceled';
}
