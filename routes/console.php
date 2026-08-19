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
    Schedule::command('shopify:sync-reconcile --mode=incremental')
        ->name('shopify:incremental-sync')
        ->everyFiveMinutes()
        ->onOneServer()
        ->withoutOverlapping(10);

    Schedule::command('shopify:sync-reconcile --mode=reconcile')
        ->name('shopify:daily-sync-reconciliation')
        ->dailyAt((string) config('shopify.scheduled_sync.reconciliation_time', '03:00'))
        ->timezone((string) config('shopify.scheduled_sync.timezone', 'America/New_York'))
        ->onOneServer()
        ->withoutOverlapping(180);

    Schedule::command('shopify:sync-reconcile --mode=full')
        ->name('shopify:weekly-full-sync')
        ->weeklyOn(
            (int) config('shopify.scheduled_sync.full_sync_weekday', 1),
            (string) config('shopify.scheduled_sync.full_sync_time', '02:00'),
        )
        ->timezone((string) config('shopify.scheduled_sync.timezone', 'America/New_York'))
        ->onOneServer()
        ->withoutOverlapping(360);

    Schedule::command('shopify:register-webhooks')
        ->name('shopify:webhook-subscription-reconciliation')
        ->dailyAt('01:30')
        ->timezone((string) config('shopify.scheduled_sync.timezone', 'America/New_York'))
        ->onOneServer()
        ->withoutOverlapping(60);
}
