<?php

namespace App\Enums;

enum PrimaryGoal: string
{
    case Schedule = 'schedule';
    case Billing = 'billing';
    case Teaching = 'teaching';
    case Growth = 'growth';
}
