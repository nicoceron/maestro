<?php

namespace App\Enums;

enum InstrumentRelationship: string
{
    case Studies = 'studies';
    case Teaches = 'teaches';
    case Both = 'both';
}
