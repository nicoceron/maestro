<?php

namespace App\Enums;

enum EventOccurrenceStatus: string
{
    case Tentative = 'tentative';
    case Scheduled = 'scheduled';
    case Completed = 'completed';
    case Canceled = 'canceled';
}
