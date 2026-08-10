<?php

namespace App\Enums;

enum PersonStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';
}
