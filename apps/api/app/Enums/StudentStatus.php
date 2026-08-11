<?php

namespace App\Enums;

enum StudentStatus: string
{
    case Lead = 'lead';
    case Trial = 'trial';
    case Waiting = 'waiting';
    case Active = 'active';
    case Paused = 'paused';
    case Former = 'former';
}
