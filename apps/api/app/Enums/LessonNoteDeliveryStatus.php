<?php

namespace App\Enums;

enum LessonNoteDeliveryStatus: string
{
    case Pending = 'pending';
    case Committed = 'committed';
    case Canceled = 'canceled';
}
