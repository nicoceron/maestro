<?php

namespace App\Enums;

enum AttendanceMakeupDisposition: string
{
    case None = 'none';
    case Required = 'required';
    case Waived = 'waived';
    case PendingReview = 'pending_review';
}
