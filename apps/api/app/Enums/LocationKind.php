<?php

namespace App\Enums;

enum LocationKind: string
{
    case Physical = 'physical';
    case Online = 'online';
    case Mobile = 'mobile';
}
