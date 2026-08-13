<?php

namespace App\Enums;

enum EventAssignmentRole: string
{
    case Lead = 'lead';
    case Assistant = 'assistant';
    case Substitute = 'substitute';
}
