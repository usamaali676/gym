<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs a cron entry: * * * * * php /path/to/artisan schedule:run
Schedule::command('ironpulse:send-digests')->hourly()->withoutOverlapping();
Schedule::command('ironpulse:prune-guests')->dailyAt('03:30')->withoutOverlapping();

// Mobile API housekeeping
Schedule::command('membership:maintain')->hourly()->withoutOverlapping();
Schedule::command('api:prune')->dailyAt('03:45')->withoutOverlapping();
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('04:00')->withoutOverlapping();
