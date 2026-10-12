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
