<?php

namespace App\Enums;

enum EmploymentType: string
{
    case Employee = 'employee';
    case Contractor = 'contractor';
    case Volunteer = 'volunteer';
}
