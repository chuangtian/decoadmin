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

Schedule::command('shopify:scan-alerts')
    ->name('shopify:scan-operational-alerts')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

if (config('shopify.scheduled_sync.enabled')) {
    Schedule::command('shopify:sync-reconcile')
        ->name('shopify:daily-sync-reconciliation')
        ->dailyAt((string) config('shopify.scheduled_sync.time', '03:00'))
        ->timezone((string) config('shopify.scheduled_sync.timezone', 'America/New_York'))
        ->onOneServer()
        ->withoutOverlapping(180);
}
