<?php

namespace App\Providers;

use App\Models\App as ShopifyApp;
use App\Models\Role;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Policies\AppPolicy;
use App\Policies\RolePolicy;
use App\Policies\StorePolicy;
use App\Policies\SyncJobPolicy;
use App\Policies\UserPolicy;
use App\Policies\WebhookEventPolicy;
use App\Services\AppCenter\AppConfigurationCatalog;
use App\Services\AppCenter\GenericAppConfigurationProvider;
use App\Services\AppCenter\StudentDiscountAppConfigurationProvider;
use App\Services\Shopify\Sync\Handlers\CustomerSyncHandler;
use App\Services\Shopify\Sync\Handlers\InventorySyncHandler;
use App\Services\Shopify\Sync\Handlers\OrderSyncHandler;
use App\Services\Shopify\Sync\Handlers\ProductSyncHandler;
use App\Services\Shopify\Sync\SyncHandlerRegistry;
use App\Services\Shopify\Webhooks\Handlers\AppUninstalledHandler;
use App\Services\Shopify\Webhooks\Handlers\OrdersCreatedHandler;
use App\Services\Shopify\Webhooks\Handlers\ProductsUpdatedHandler;
use App\Services\Shopify\Webhooks\Handlers\ShopifyDataWebhookHandler;
use App\Services\Shopify\Webhooks\ShopifyIncrementalDataService;
use App\Services\Shopify\Webhooks\WebhookHandlerRegistry;
use App\Services\SystemSettingsService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);
        $this->app->scoped(CurrentStore::class);
        $this->app->tag([
            StudentDiscountAppConfigurationProvider::class,
            GenericAppConfigurationProvider::class,
        ], 'app-center.configuration-providers');
        $this->app->singleton(AppConfigurationCatalog::class, fn ($app) => new AppConfigurationCatalog(
            $app->tagged('app-center.configuration-providers'),
        ));
        $this->app->singleton(WebhookHandlerRegistry::class, function ($app): WebhookHandlerRegistry {
            $data = $app->make(ShopifyIncrementalDataService::class);
            $topics = [
                'products/create', 'products/delete',
                'orders/updated', 'orders/cancelled',
                'customers/create', 'customers/update', 'customers/delete',
                'inventory_levels/update',
            ];

            return new WebhookHandlerRegistry([
                ...array_map(fn (string $topic) => new ShopifyDataWebhookHandler($topic, $data), $topics),
                $app->make(ProductsUpdatedHandler::class),
                $app->make(OrdersCreatedHandler::class),
                $app->make(AppUninstalledHandler::class),
            ]);
        });
        $this->app->singleton(SyncHandlerRegistry::class, fn ($app) => new SyncHandlerRegistry([
            $app->make(ProductSyncHandler::class),
            $app->make(OrderSyncHandler::class),
            $app->make(CustomerSyncHandler::class),
            $app->make(InventorySyncHandler::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('student-discount-public', function (Request $request) {
            $key = $this->studentDiscountRateLimitKey($request);

            return $key === null
                ? Limit::none()
                : Limit::perMinute(60)->by('student-discount-public:'.$key);
        });
        RateLimiter::for('student-discount-submissions', function (Request $request) {
            $key = $this->studentDiscountRateLimitKey($request);
            if ($key === null) {
                return Limit::none();
            }

            return Limit::perHour(5)
                ->by('student-discount-submission:'.$key)
                ->response(function (Request $request, array $headers) {
                    return response()->json(['error' => [
                        'code' => 'STUDENT_DISCOUNT_RATE_LIMITED',
                        'message' => '提交过于频繁，请稍后重试。',
                        'retry_after' => (int) ($headers['Retry-After'] ?? 3600),
                    ]], 429, $headers);
                });
        });

        try {
            if (Schema::hasTable('system_settings')) {
                app(SystemSettingsService::class)->applyRuntimeConfiguration();
            }
        } catch (Throwable) {
            // The application must remain bootable while the database is unavailable or migrating.
        }

        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);
        Gate::policy(Store::class, StorePolicy::class);
        Gate::policy(SyncJob::class, SyncJobPolicy::class);
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

    private function studentDiscountRateLimitKey(Request $request): ?string
    {
        $store = $request->attributes->get('student_discount_store');
        if (! $store instanceof Store) {
            return null;
        }

        return hash_hmac(
            'sha256',
            $store->id.'|'.(string) $request->ip(),
            (string) config('app.key'),
        );
    }
}
