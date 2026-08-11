<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('invitations:dispatch-pending')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping(5);

Schedule::command('invitations:redact-terminal-digests')
    ->dailyAt('03:20')
    ->onOneServer()
    ->withoutOverlapping(60);
