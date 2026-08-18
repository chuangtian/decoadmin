<?php

namespace App\Services\Shopify\Sync;

use App\Models\AppInstallation;
use App\Models\SyncJob;
use App\Services\Sync\SyncJobService;
use Carbon\CarbonImmutable;

class ScheduledShopifySyncService
{
    /** @var array<string, list<string>> */
    private const REQUIRED_SCOPES = [
        'products' => ['read_products'],
        'orders' => ['read_orders'],
        'customers' => ['read_customers'],
        'inventory' => ['read_inventory'],
    ];

    public function __construct(private SyncJobService $syncJobs) {}

    /**
     * @return array{stores: int, created: int, duplicate: int, missing_scope: int}
     */
    public function dispatch(?int $storeId = null): array
    {
        $timezone = (string) config('shopify.scheduled_sync.timezone', 'America/New_York');
        $dayStartedAt = CarbonImmutable::now($timezone)->startOfDay()->utc();
        $installations = AppInstallation::query()
            ->where('status', 'active')
            ->whereHas('store', fn ($query) => $query->where('status', 'active'))
            ->whereHas('shopifyConnection', fn ($query) => $query->whereIn('status', ['connected', 'warning']))
            ->with(['store', 'shopifyConnection'])
            ->latest('installed_at');

        if ($storeId !== null) {
            $installations->where('store_id', $storeId);
        }

        $result = ['stores' => 0, 'created' => 0, 'duplicate' => 0, 'missing_scope' => 0];

        $installations->get()->unique('store_id')->each(function (AppInstallation $installation) use ($dayStartedAt, &$result): void {
            $store = $installation->store;

            if (! $store) {
                return;
            }

            $result['stores']++;
            $grantedScopes = array_values(array_unique([
                ...($installation->granted_scopes ?? []),
                ...($installation->shopifyConnection?->scopes ?? []),
            ]));

            foreach (self::REQUIRED_SCOPES as $type => $requiredScopes) {
                if (array_diff($requiredScopes, $grantedScopes) !== []) {
                    $result['missing_scope']++;

                    continue;
                }

                $alreadyScheduled = SyncJob::query()
                    ->where('store_id', $store->getKey())
                    ->where('type', $type)
                    ->whereIn('status', ['pending', 'queued', 'running', 'completed'])
                    ->where('created_at', '>=', $dayStartedAt)
                    ->exists();

                if ($alreadyScheduled) {
                    $result['duplicate']++;

                    continue;
                }

                $this->syncJobs->createScheduledAndDispatch($store, $type, $installation);
                $result['created']++;
            }
        });

        return $result;
    }
}
