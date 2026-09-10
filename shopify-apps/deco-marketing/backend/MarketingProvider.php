<?php

namespace DecoMarketing;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class MarketingProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/marketing.php', 'marketing');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../views', 'marketing');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Commands\RunMarketing::class, Commands\SyncMarketing::class]);
            $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
                if (config('marketing.sync_scheduled') && config('marketing.reconciliation') && app()->environment('staging')) {
                    $store = \App\Models\Store::where('shopify_domain', \DecoMarketing\Services\Guard::RECONCILIATION_SHOP)->sole();
                    $schedule->command('marketing:sync', ['--store' => $store->id, '--pages' => 20])->everyFiveMinutes()->withoutOverlapping(60);
                }
                if (config('marketing.scheduled') && app()->environment(['local', 'test', 'staging'])) {
                    $schedule->command('marketing:run')->everyMinute()->withoutOverlapping(10);
                }
            });
        }
    }
}
