<?php

namespace App\Enums;

enum EventKind: string
{
    case General = 'general';
    case PrivateLesson = 'private_lesson';
    case GroupClass = 'group_class';
    case OpenClass = 'open_class';
    case Workshop = 'workshop';
    case Camp = 'camp';
    case Recital = 'recital';
    case Closure = 'closure';
}
