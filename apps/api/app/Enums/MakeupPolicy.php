<?php

namespace App\Enums;

enum MakeupPolicy: string
{
    case None = 'none';
    case StudioCredit = 'studio_credit';
    case Reschedule = 'reschedule';
}
