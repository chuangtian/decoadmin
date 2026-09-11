<?php

namespace App\Services\Shopify\Products;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ShopifyProductMonitor;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreOperationalAlertService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ShopifyProductMonitorService
{
    public function __construct(private ShopifyProductMonitorReader $reader, private StoreOperationalAlertService $alerts) {}

    public function configure(Store $store, User $actor, Product $product, bool $enabled, int $threshold): ShopifyProductMonitor
    {
        abort_unless($store->status === 'active' && $store->organization?->status === 'active'
            && (int) $product->store_id === (int) $store->id && (int) $product->organization_id === (int) $store->organization_id
            && $actor->canAccessStore($store) && $actor->hasPermission('products.update', $store->organization, $store), 403);
        abort_unless($threshold >= 0 && $threshold <= 1000000, 422);

        return DB::transaction(function () use ($store, $actor, $product, $enabled, $threshold) {
            // Serialize first creation as well as updates without resetting an unchanged baseline.
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $monitor = ShopifyProductMonitor::firstOrNew(['store_id' => $store->id, 'shopify_product_id' => $product->shopify_product_id]);
            abort_if($monitor->exists && (int) $monitor->organization_id !== (int) $store->organization_id, 403);
            if ($monitor->exists && $monitor->is_enabled === $enabled && $monitor->low_stock_threshold === $threshold) {
                return $monitor;
            }
            $before = $monitor->only(['is_enabled', 'low_stock_threshold']);
            $monitor->fill(['organization_id' => $store->organization_id, 'product_id' => $product->id,
                'is_enabled' => $enabled, 'low_stock_threshold' => $threshold, 'snapshot' => null,
                'generation' => ($monitor->generation ?? 0) + 1, 'last_error' => null, 'last_checked_at' => null, 'last_success_at' => null])->save();
            AuditLog::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'user_id' => $actor->id,
                'action' => 'shopify_product_monitor_configured', 'subject_type' => ShopifyProductMonitor::class,
                'subject_id' => $monitor->id, 'old_values' => $before, 'new_values' => $monitor->only(['is_enabled', 'low_stock_threshold'])]);

            return $monitor;
        });
    }

    public function scanStore(Store $store): array
    {
        $result = ['checked' => 0, 'alerts' => 0, 'failed' => 0];
        if ($store->status !== 'active' || $store->organization?->status !== 'active') {
            return $result;
        }
        ShopifyProductMonitor::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('is_enabled', true)->eachById(function ($monitor) use ($store, &$result) {
                Cache::lock('product-monitor:'.$monitor->id, 1800)->get(function () use ($store, $monitor, &$result) {
                    $monitor->refresh();
                    if (! $monitor->is_enabled) {
                        return;
                    }
                    $result['checked']++;
                    try {
                        $snapshot = $this->reader->read($store, $monitor->shopify_product_id);
                        $result['alerts'] += $this->process($store, $monitor, $snapshot);
                    } catch (Throwable $exception) {
                        // Never serialize API errors, tokens or raw payloads to the UI/logs.
                        ShopifyProductMonitor::whereKey($monitor->id)->where('generation', $monitor->generation)
                            ->where('is_enabled', true)->update(['last_checked_at' => now(), 'last_error' => '读取失败，请检查店铺连接及商品、库存、地点读取权限；下次自动重试。']);
                        $result['failed']++;
                    }
                });
            }, 25);

        return $result;
    }

    private function process(Store $store, ShopifyProductMonitor $monitor, array $snapshot): int
    {
        return DB::transaction(function () use ($store, $monitor, $snapshot) {
            $locked = ShopifyProductMonitor::whereKey($monitor->id)->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)->lockForUpdate()->firstOrFail();
            if (! $locked->is_enabled || $locked->generation !== $monitor->generation) {
                return 0;
            }
            $previous = $locked->snapshot;
            // A disappeared object retains its identity/label, never means zero inventory.
            if ($snapshot['missing'] && $previous) {
                $snapshot = [...$previous, 'missing' => true];
            }
            $changes = $previous === null ? [] : $this->changes($previous, $snapshot, $locked->low_stock_threshold);
            $locked->fill(['snapshot' => $snapshot, 'revision' => $locked->revision + 1,
                'last_checked_at' => now(), 'last_success_at' => now(), 'last_error' => null])->save();
            if ($changes === []) {
                return 0;
            }
            $label = Str::limit((string) ($snapshot['title'] ?? $monitor->shopify_product_id), 120);
            // Revision identifies an occurrence: A -> B -> A -> B must alert again.
            $message = "产品：{$label}\nShopify 产品 ID：{$monitor->shopify_product_id}\n".implode("\n", array_slice($changes, 0, 12));
            if (count($changes) > 12) {
                $message .= "\n另有 ".(count($changes) - 12).' 项变化，请查看后台告警详情。';
            }

            return (int) $this->alerts->record($store, 'product', ShopifyProductMonitor::class, $locked->id,
                'product_change_'.$locked->generation.'_'.$locked->revision, '重点产品库存或状态发生变化', $message,
                'warning', ['shopify_product_id' => $locked->shopify_product_id, 'generation' => $locked->generation,
                    'changes' => $changes, 'low_stock_threshold' => $locked->low_stock_threshold], now());
        });
    }

    private function changes(array $before, array $after, int $threshold): array
    {
        if ($before['missing'] !== $after['missing']) {
            return [$after['missing'] ? '产品：存在 → Shopify 中已找不到' : '产品：Shopify 中找不到 → 已恢复'];
        }
        if ($after['missing']) {
            return [];
        }
        $changes = [];
        $statuses = ['ACTIVE' => '销售中', 'DRAFT' => '草稿', 'ARCHIVED' => '已归档'];
        if ($before['status'] !== $after['status']) {
            $changes[] = '状态：'.($statuses[$before['status']] ?? $before['status']).' → '.($statuses[$after['status']] ?? $after['status']);
        }
        if ($before['published'] !== $after['published']) {
            $changes[] = '在线商店：'.($before['published'] ? '已发布 → 已取消发布' : '未发布 → 已发布');
        }
        foreach ($before['variants'] as $id => $old) {
            $new = $after['variants'][$id] ?? null;
            $label = Str::limit($old['title'].' / '.$old['sku'], 90).'（'.$id.'）';
            if ($new === null) {
                $changes[] = $label.'：变体已移除';

                continue;
            }
            if ($old['tracked'] !== $new['tracked']) {
                $changes[] = $label.'：库存跟踪'.($new['tracked'] ? '已开启' : '已关闭');

                continue;
            }
            foreach ($old['levels'] as $locationId => $level) {
                if (! isset($new['levels'][$locationId])) {
                    $changes[] = $label.' / '.$level['name'].'：库存地点已移除或停用';

                    continue;
                }
                $a = $level['available'];
                $b = $new['levels'][$locationId]['available'];
                $category = fn (int $quantity) => $quantity <= 0 ? '缺货' : ($quantity <= $threshold ? '低库存' : '正常');
                if ($category($a) !== $category($b)) {
                    $changes[] = $label.' / '.$level['name'].'：'.$category($a)."（{$a}） → ".$category($b)."（{$b}）";
                }
            }
        }

        return $changes;
    }
}
