<?php

namespace App\Enums;

enum EventEnrollmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Waitlisted = 'waitlisted';
    case Withdrawn = 'withdrawn';
}
