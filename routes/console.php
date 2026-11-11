<?php

use App\Console\BackupScheduleRegistrar;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('attempts:detect-disconnected')->everyMinute()->withoutOverlapping();

// Scheduled database backup (disabled by default, see config/backup.php).
BackupScheduleRegistrar::registerFromConfig(app(\Illuminate\Console\Scheduling\Schedule::class));
