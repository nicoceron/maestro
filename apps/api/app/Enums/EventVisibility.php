<?php

namespace App\Enums;

enum EventVisibility: string
{
    case Private = 'private';
    case Studio = 'studio';
    case Portal = 'portal';
    case Public = 'public';
}
