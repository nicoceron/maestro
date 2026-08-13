<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('crm-data-portability:purge')->hourlyAt(35)->onOneServer()->withoutOverlapping(30);
