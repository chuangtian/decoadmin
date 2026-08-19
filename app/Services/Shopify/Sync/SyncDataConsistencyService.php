<?php

namespace App\Services\Shopify\Sync;

use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\SyncJob;
use App\Services\StoreOperationalAlertService;

class SyncDataConsistencyService
{
    public function __construct(private StoreOperationalAlertService $alerts) {}

    public function inspect(SyncJob $job, SyncResult $result): SyncResult
    {
        if (! in_array($job->mode, ['full', 'reconcile'], true) || ! $job->store) {
            return $result;
        }

        $localCount = $this->localCount($job);
        $remoteCount = $result->recordsCount;
        $difference = abs($localCount - $remoteCount);
        $consistent = $difference === 0;

        if (! $consistent) {
            $this->alerts->record(
                $job->store,
                'consistency',
                SyncJob::class,
                $job->getKey(),
                'data_count_mismatch',
                'Shopify 数据一致性异常',
                "{$job->type} 同步后，本地记录 {$localCount} 条，Shopify 返回 {$remoteCount} 条。",
                'warning',
                [
                    'sync_type' => $job->type,
                    'job_uuid' => $job->uuid,
                    'local_count' => $localCount,
                    'remote_count' => $remoteCount,
                    'difference' => $difference,
                    'correlation_id' => $job->correlation_id,
                ],
            );
        }

        return SyncResult::successful($result->message, $result->recordsCount, [
            ...$result->metadata,
            'consistency' => [
                'checked' => true,
                'consistent' => $consistent,
                'local_count' => $localCount,
                'remote_count' => $remoteCount,
                'difference' => $difference,
            ],
        ]);
    }

    private function localCount(SyncJob $job): int
    {
        $model = match ($job->type) {
            'products' => Product::class,
            'orders' => Order::class,
            'customers' => Customer::class,
            'inventory' => InventoryItem::class,
            default => null,
        };

        return $model ? $model::query()->where('store_id', $job->store_id)->count() : 0;
    }
}
