<?php

namespace App\Enums;

enum HouseholdMemberRole: string
{
    case Guardian = 'guardian';
    case Learner = 'learner';
    case Other = 'other';
}
