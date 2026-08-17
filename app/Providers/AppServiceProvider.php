<?php

namespace App\Providers;

use App\Models\App as ShopifyApp;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Policies\AppPolicy;
use App\Policies\RolePolicy;
use App\Policies\StorePolicy;
use App\Policies\UserPolicy;
use App\Policies\WebhookEventPolicy;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);
        $this->app->scoped(CurrentStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);
        Gate::policy(Store::class, StorePolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(ShopifyApp::class, AppPolicy::class);
        Gate::policy(WebhookEvent::class, WebhookEventPolicy::class);

        Gate::define('permission', function (User $user, string $permission): bool {
            return $user->hasPermission(
                $permission,
                app(CurrentOrganization::class)->get(),
                app(CurrentStore::class)->get(),
            );
        });
    }
}
