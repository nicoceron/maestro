<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tenant-data:expire-exports')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(30);

Schedule::command('tenant-data:advance-deletions')
    ->hourlyAt(15)
    ->onOneServer()
    ->withoutOverlapping(30);
