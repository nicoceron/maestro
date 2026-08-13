<?php

namespace App\Enums;

enum EventParticipantStatus: string
{
    case Reserved = 'reserved';
    case Confirmed = 'confirmed';
    case Waitlisted = 'waitlisted';
    case Canceled = 'canceled';
}
