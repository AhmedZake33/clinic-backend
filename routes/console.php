<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reservations:send-whatsapp-reminders --hours=24 --window=60')
    ->hourly()
    ->withoutOverlapping();

// Daily backup of the database and the archive files at 03:00
Schedule::command('backup:database --with-files')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Scheduled backup:database failed');
    });
