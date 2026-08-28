<?php

namespace App\Services\Shopify\Webhooks\Handlers;

use App\Contracts\Shopify\WebhookHandlerInterface;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\WebhookEvent;
use App\Services\Shopify\ShopifyConnectionLifecycleService;
use Illuminate\Support\Facades\DB;

class AppUninstalledHandler implements WebhookHandlerInterface
{
    public function __construct(private ShopifyConnectionLifecycleService $lifecycle) {}

    public function topic(): string
    {
        return 'app/uninstalled';
    }

    public function handle(WebhookEvent $event): void
    {
        $event->loadMissing(['app', 'shopifyConnection.appInstallations']);
        $connection = $event->shopifyConnection;

        if (! $connection) {
            return;
        }

        if (data_get($event->app?->settings, 'oauth_token_storage') === 'app_installation') {
            $this->markApplicationInstallationUninstalled($event);

            return;
        }

        $this->lifecycle->markUninstalled($connection, 'Shopify 应用已从店铺卸载。');
    }

    private function markApplicationInstallationUninstalled(WebhookEvent $event): void
    {
        DB::transaction(function () use ($event): void {
            $installation = AppInstallation::query()
                ->where('app_id', $event->app_id)
                ->where('store_id', $event->store_id)
                ->lockForUpdate()
                ->first();

            if (! $installation || $installation->status === 'uninstalled') {
                return;
            }

            $installation->forceFill([
                'status' => 'uninstalled',
                'access_token_encrypted' => null,
                'refresh_token_encrypted' => null,
                'token_type' => null,
                'access_token_expires_at' => null,
                'refresh_token_expires_at' => null,
                'uninstalled_at' => now(),
            ])->save();

            AuditLog::query()->create([
                'organization_id' => $event->organization_id,
                'store_id' => $event->store_id,
                'action' => 'shopify_app_uninstalled',
                'subject_type' => AppInstallation::class,
                'subject_id' => $installation->getKey(),
                'metadata' => [
                    'scope' => 'store',
                    'app_id' => $event->app_id,
                    'app_handle' => $event->app?->handle,
                    'environment' => data_get($event->app?->settings, 'environment'),
                ],
            ]);
        });
    }
}
