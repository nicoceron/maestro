<?php

namespace App\Enums;

enum EventAssignmentStatus: string
{
    case Assigned = 'assigned';
    case Canceled = 'canceled';
}
