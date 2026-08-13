<?php

namespace App\Enums;

enum CustomFieldAppliesTo: string
{
    case Person = 'person';
    case Student = 'student';
    case Staff = 'staff';
}
