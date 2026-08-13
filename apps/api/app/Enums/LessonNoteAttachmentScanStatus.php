<?php

namespace App\Enums;

enum LessonNoteAttachmentScanStatus: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Failed = 'failed';
}
