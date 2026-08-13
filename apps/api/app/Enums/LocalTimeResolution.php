<?php

namespace App\Enums;

enum LocalTimeResolution: string
{
    case Reject = 'reject';
    case Earlier = 'earlier';
    case Later = 'later';
    case NormalizeForward = 'normalize_forward';
}
