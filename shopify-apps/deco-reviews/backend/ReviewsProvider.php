<?php

namespace DecoReviews;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ReviewsProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/deco_reviews.php', 'deco_reviews');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../views', 'deco-reviews');
        $this->loadRoutesFrom(__DIR__.'/../routes.php');
        if ($this->app->runningInConsole()) {
            $this->commands([Commands\PublishReviews::class, Commands\DispatchInvitations::class, Commands\DispatchReviewEmails::class, Commands\DispatchRewards::class]);
            $this->app->afterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('deco-reviews:publish')->everyFiveMinutes()->withoutOverlapping(10));
            $this->app->afterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('deco-reviews:dispatch')->everyFiveMinutes()->withoutOverlapping(10));
            $this->app->afterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('deco-reviews:dispatch-emails')->everyFiveMinutes()->withoutOverlapping(10));
            $this->app->afterResolving(Schedule::class, fn (Schedule $schedule) => $schedule->command('deco-reviews:dispatch-rewards')->everyFiveMinutes()->withoutOverlapping(10));
        }
    }
}
