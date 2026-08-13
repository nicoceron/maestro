<?php

namespace App\Enums;

enum CustomFieldType: string
{
    case Text = 'text';
    case LongText = 'long_text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Select = 'select';
    case MultiSelect = 'multi_select';
}
