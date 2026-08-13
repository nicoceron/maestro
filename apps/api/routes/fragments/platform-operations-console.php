<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('outbox:relay --limit=250')->everyMinute()->onOneServer()->withoutOverlapping(5);
Schedule::command('support-access:expire --limit=500')->everyMinute()->onOneServer()->withoutOverlapping(5);
