<?php

namespace App\Enums;

enum GuardianRelationshipType: string
{
    case Parent = 'parent';
    case LegalGuardian = 'legal_guardian';
    case Grandparent = 'grandparent';
    case Carer = 'carer';
    case Other = 'other';
}
