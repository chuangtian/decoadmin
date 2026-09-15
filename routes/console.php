<?php

use App\Services\SystemStatusService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('finance:process-auto-renewals')
    ->name('finance:process-auto-renewals')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(10);
if (config('product_monitoring.enabled', true)) {
    Schedule::command('shopify:monitor-products')->everyFiveMinutes()->onOneServer()->withoutOverlapping(60);
}
Schedule::call([SystemStatusService::class, 'recordSchedulerHeartbeat'])
    ->name('system:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('shopify:scan-alerts')
    ->name('shopify:scan-operational-alerts')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

if (config('discount_monitoring.enabled', true)) {
    $discountMonitor = Schedule::command('shopify:monitor-discounts')
        ->name('shopify:monitor-priority-discounts');
    if ((int) config('discount_monitoring.poll_minutes', 5) >= 10) {
        $discountMonitor->everyTenMinutes();
    } else {
        $discountMonitor->everyFiveMinutes();
    }
    $discountMonitor
        ->onOneServer()
        ->withoutOverlapping(10);
}

Schedule::command('shopify:prune-analytics-snapshots')
    ->name('shopify:prune-analytics-snapshots')
    ->dailyAt('04:15')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('shopify:prune-uninstalled-data')
    ->name('shopify:prune-uninstalled-data')
    ->hourlyAt(20)
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('personalization:reconcile-attribution')
    ->name('personalization:reconcile-attribution')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(10);

Schedule::command('personalization:prune-data --due-only')
    ->name('personalization:purge-due-stores')
    ->hourlyAt(35)
    ->onOneServer()
    ->withoutOverlapping(30);

Schedule::command('personalization:prune-data')
    ->name('personalization:retention-maintenance')
    ->dailyAt('04:25')
    ->onOneServer()
    ->withoutOverlapping(120);

Schedule::command('student-discounts:prune-evidence --days=30')
    ->name('student-discounts:prune-reviewed-evidence')
    ->dailyAt('04:35')
    ->onOneServer()
    ->withoutOverlapping(60);

Schedule::command('instagram-feed:advance-mirrors')
    ->name('instagram-feed:advance-pending-media-mirrors')
    ->everyTenMinutes()
    ->onOneServer()
    ->withoutOverlapping(30);

Schedule::command('shopify:check-connections')
    ->name('shopify:connection-and-store-metadata-refresh')
    ->everySixHours()
    ->onOneServer()
    ->withoutOverlapping(60);

if (config('services.meta_ads.sync_enabled', true)) {
    Schedule::command('meta-ads:sync --mode=incremental')
        ->name('meta-ads:hourly-insight-sync')
        ->hourlyAt(10)
        ->onOneServer()
        ->withoutOverlapping(10);

    Schedule::command('meta-ads:sync --mode=structure')
        ->name('meta-ads:daily-structure-sync')
        ->dailyAt((string) config('services.meta_ads.structure_sync_time', '02:35'))
        ->timezone((string) config('services.meta_ads.structure_sync_timezone', 'UTC'))
        ->onOneServer()
        ->withoutOverlapping(120);
}

if (config('services.advertising_sync.enabled', true)) {
    Schedule::command('advertising-channels:sync google --mode=realtime')
        ->name('advertising-channels:google:five-minute-core-sync')
        ->everyFiveMinutes()
        ->onOneServer()
        ->withoutOverlapping(10);

    Schedule::command('advertising-channels:sync google --mode=attribution')
        ->name('advertising-channels:google:hourly-attribution-sync')
        ->hourlyAt(37)
        ->onOneServer()
        ->withoutOverlapping(45);

    Schedule::command('advertising-channels:sync google --mode=reconcile')
        ->name('advertising-channels:google:daily-attribution-reconciliation')
        ->dailyAt('04:47')
        ->timezone('UTC')
        ->onOneServer()
        ->withoutOverlapping(120);

    foreach (['google' => 17, 'tiktok' => 29, 'bing' => 41, 'criteo' => 53] as $channel => $minute) {
        Schedule::command("advertising-channels:sync {$channel} --mode=incremental")
            ->name("advertising-channels:{$channel}:hourly-store-sync")
            ->hourlyAt($minute)
            ->onOneServer()
            ->withoutOverlapping(45);
    }
}

if (config('services.feishu_table.amazon_sync_enabled', true)) {
    Schedule::command('feishu:sync-amazon-daily-sales')
        ->name('feishu:amazon-daily-sales-sync')
        ->dailyAt((string) config('services.feishu_table.amazon_sync_time', '09:00'))
        ->timezone((string) config('services.feishu_table.amazon_sync_timezone', 'Asia/Shanghai'))
        ->onOneServer()
        ->withoutOverlapping(120);
}

if (config('services.feishu_table.paid_advertising_goal_sync_enabled', true)) {
    Schedule::command('feishu:sync-paid-advertising-goals --google-only')
        ->name('feishu:google-ads-tables-five-minute-sync')
        ->everyFiveMinutes()
        ->onOneServer()
        ->withoutOverlapping(10);

    Schedule::command('feishu:sync-paid-advertising-goals')
        ->name('feishu:paid-advertising-goals-and-all-tables-sync')
        ->dailyAt((string) config('services.feishu_table.paid_advertising_goal_sync_time', '03:40'))
        ->timezone((string) config('services.feishu_table.paid_advertising_goal_sync_timezone', 'Asia/Shanghai'))
        ->onOneServer()
        ->withoutOverlapping(120);
}

if (config('services.feishu_table.mf_daily_report_sync_enabled', true)) {
    Schedule::command('feishu:sync-mf-daily-reports')
        ->name('feishu:mf-daily-report-sync-and-delivery')
        ->dailyAt((string) config('services.feishu_table.mf_daily_report_sync_time', '15:30'))
        ->timezone((string) config('services.feishu_table.mf_daily_report_sync_timezone', 'Asia/Shanghai'))
        ->onOneServer()
        ->withoutOverlapping(120);
}

if (config('services.feishu_table.campaign_sync_enabled', true)) {
    Schedule::command('feishu:sync-campaign-activities')
        ->name('feishu:campaign-activities-and-all-tables-sync')
        ->dailyAt((string) config('services.feishu_table.campaign_sync_time', '04:10'))
        ->timezone((string) config('services.feishu_table.campaign_sync_timezone', 'Asia/Shanghai'))
        ->onOneServer()
        ->withoutOverlapping(180);
}

if (config('services.feishu_table.natural_traffic_sync_enabled', true)) {
    Schedule::command('natural-traffic:sync')
        ->name('natural-traffic:daily-local-database-sync')
        ->dailyAt((string) config('services.feishu_table.natural_traffic_sync_time', '04:25'))
        ->timezone((string) config('services.feishu_table.natural_traffic_sync_timezone', 'Asia/Shanghai'))
        ->onOneServer()
        ->withoutOverlapping(180);
}

Schedule::command('reputation:sync')
    ->name('reputation:daily-local-database-sync')
    ->dailyAt('04:55')
    ->timezone('Asia/Shanghai')
    ->onOneServer()
    ->withoutOverlapping(180);

if (config('services.google_search_console.sync_enabled', true)) {
    Schedule::command('seo-analytics:sync --mode=incremental')
        ->name('seo-analytics:daily-local-database-sync')
        ->dailyAt((string) config('services.google_search_console.sync_time', '04:40'))
        ->timezone((string) config('services.google_search_console.sync_timezone', 'America/Los_Angeles'))
        ->onOneServer()
        ->withoutOverlapping(120);
}

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

// The pilot's queue and maintenance must survive the standard staging deployment.
if (app()->environment('staging')) {
    Schedule::command('affiliate:maintenance')
        ->name('affiliate:pilot-maintenance')
        ->everyMinute()
        ->onOneServer()
        ->withoutOverlapping(5);
}
