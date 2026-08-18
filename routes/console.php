<?php

use App\Services\SystemStatusService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::call([SystemStatusService::class, 'recordSchedulerHeartbeat'])
    ->name('system:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();
