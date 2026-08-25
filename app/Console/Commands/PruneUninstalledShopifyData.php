<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ShopifyConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PruneUninstalledShopifyData extends Command
{
    protected $signature = 'shopify:prune-uninstalled-data {--store= : 仅处理指定店铺 ID}';

    protected $description = '清除已卸载 Shopify 应用的个人数据和过期配置';

    public function handle(): int
    {
        $query = ShopifyConnection::query()
            ->whereNotNull('uninstalled_at')
            ->where('status', 'disconnected')
            ->with(['store', 'appInstallations']);

        if ($storeId = $this->option('store')) {
            $query->where('store_id', $storeId);
        }

        $processed = 0;
        $query->chunkById(100, function ($connections) use (&$processed): void {
            foreach ($connections as $connection) {
                $processed += $this->prune($connection);
            }
        });

        $this->info("已完成 {$processed} 项卸载数据清理任务。");

        return self::SUCCESS;
    }

    private function prune(ShopifyConnection $connection): int
    {
        $metadata = $connection->metadata ?? [];
        $processed = 0;

        if (! isset($metadata['personal_data_erased_at'])
            && $this->isDue($metadata['personal_data_erase_at'] ?? null)) {
            DB::transaction(function () use ($connection, &$metadata): void {
                $orderIds = DB::table('orders')->where('store_id', $connection->store_id)->select('id');
                DB::table('order_items')->whereIn('order_id', $orderIds)->update([
                    'shopify_staff_id' => null,
                    'staff_name' => null,
                ]);
                DB::table('orders')->where('store_id', $connection->store_id)->update([
                    'shopify_customer_id' => null,
                    'email' => null,
                    'pos_staff_id' => null,
                    'pos_staff_name' => null,
                ]);
                DB::table('customers')->where('store_id', $connection->store_id)->delete();
                DB::table('storefront_events')->where('store_id', $connection->store_id)->delete();
                DB::table('webhook_events')->where('store_id', $connection->store_id)->update([
                    'headers' => json_encode(['erased' => true], JSON_THROW_ON_ERROR),
                    'payload' => json_encode(['erased' => true], JSON_THROW_ON_ERROR),
                    'payload_encrypted' => null,
                    'payload_sha256' => null,
                ]);

                $metadata['personal_data_erased_at'] = now()->toIso8601String();
                $connection->forceFill(['metadata' => $metadata])->save();
                $this->audit($connection, 'shopify_personal_data_erased');
            });
            $processed++;
        }

        if (! isset($metadata['configuration_purged_at'])
            && $this->isDue($metadata['configuration_purge_at'] ?? null)) {
            DB::transaction(function () use ($connection, &$metadata): void {
                $connection->appInstallations()->update(['settings' => null]);
                $metadata['configuration_purged_at'] = now()->toIso8601String();
                $connection->forceFill(['metadata' => $metadata])->save();
                $this->audit($connection, 'shopify_configuration_purged');
            });
            $processed++;
        }

        return $processed;
    }

    private function isDue(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        return Carbon::parse($value)->isPast();
    }

    private function audit(ShopifyConnection $connection, string $action): void
    {
        AuditLog::query()->create([
            'organization_id' => $connection->store?->organization_id,
            'store_id' => $connection->store_id,
            'action' => $action,
            'subject_type' => $connection->getMorphClass(),
            'subject_id' => $connection->getKey(),
            'metadata' => ['uninstalled_at' => $connection->uninstalled_at?->toIso8601String()],
        ]);
    }
}
