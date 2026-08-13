<?php

namespace App\Enums;

enum AttendanceBillingDisposition: string
{
    case Bill = 'bill';
    case NoCharge = 'no_charge';
    case Credit = 'credit';
    case PendingReview = 'pending_review';
}
