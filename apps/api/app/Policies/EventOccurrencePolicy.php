<?php

namespace App\Policies;

use App\Models\EventOccurrence;
use App\Models\EventSeries;
use App\Models\Studio;
use App\Models\User;

final class EventOccurrencePolicy
{
    public function viewAny(User $user, Studio $studio): bool
    {
        return $user->can('viewAny', [EventSeries::class, $studio]);
    }

    public function view(User $user, EventOccurrence $occurrence): bool
    {
        return $user->can('view', $occurrence->series);
    }

    public function update(User $user, EventOccurrence $occurrence): bool
    {
        return $user->can('update', $occurrence->series);
    }
}
