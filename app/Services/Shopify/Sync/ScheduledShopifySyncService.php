<?php

namespace App\Services\Shopify\Sync;

use App\Models\AppInstallation;
use App\Services\Sync\SyncJobService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

class ScheduledShopifySyncService
{
    /** @var array<string, list<string>> */
    private const REQUIRED_SCOPES = [
        'products' => ['read_products', 'read_inventory'],
        'orders' => ['read_orders', 'read_products'],
        'customers' => ['read_customers'],
        'inventory' => ['read_inventory', 'read_products'],
    ];

    public function __construct(
        private SyncJobService $syncJobs,
        private StoreSyncStateService $states,
    ) {}

    /**
     * @return array{stores: int, created: int, duplicate: int, missing_scope: int}
     */
    public function dispatch(?int $storeId = null, string $requestedMode = 'incremental'): array
    {
        if (! in_array($requestedMode, ['full', 'incremental', 'reconcile'], true)) {
            throw new InvalidArgumentException("不支持的同步模式 [{$requestedMode}]。");
        }

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

        $installations->get()->unique('store_id')->each(function (AppInstallation $installation) use ($requestedMode, &$result): void {
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

                $state = $this->states->state($store, $type);

                if (! $this->states->isDue($state, $requestedMode)) {
                    $result['duplicate']++;

                    continue;
                }

                $window = $this->states->window($store, $type, $requestedMode);
                $created = $this->syncJobs->createAutomaticAndDispatch(
                    $store,
                    $type,
                    $installation,
                    $window['mode'],
                    $window['since_at'],
                    $window['until_at'],
                    $requestedMode === 'reconcile' ? 'reconciliation' : 'scheduled',
                    $this->idempotencyWindow($window['until_at'], $requestedMode),
                );
                $created['created'] ? $result['created']++ : $result['duplicate']++;
            }
        });

        return $result;
    }

    private function idempotencyWindow(CarbonImmutable $untilAt, string $mode): string
    {
        if ($mode !== 'incremental') {
            return $untilAt
                ->setTimezone((string) config('shopify.scheduled_sync.timezone', 'America/New_York'))
                ->format('Y-m-d');
        }

        $bucketMinutes = 5;
        $minute = intdiv($untilAt->minute, $bucketMinutes) * $bucketMinutes;

        return $untilAt->setMinute($minute)->setSecond(0)->format('Y-m-d\TH:i:00\Z');
    }
}
