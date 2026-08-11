<?php

namespace App\Enums;

enum WorkspaceMode: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Teacher = 'teacher';
}
