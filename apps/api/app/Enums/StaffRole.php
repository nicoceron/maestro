<?php

namespace App\Enums;

enum StaffRole: string
{
    case Teacher = 'teacher';
    case Office = 'office';
    case Substitute = 'substitute';
}
