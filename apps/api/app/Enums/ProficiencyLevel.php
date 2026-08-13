<?php

namespace App\Enums;

enum ProficiencyLevel: string
{
    case Beginner = 'beginner';
    case Intermediate = 'intermediate';
    case Advanced = 'advanced';
    case Professional = 'professional';
}
