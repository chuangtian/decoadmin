<?php

namespace App\Services\Discounts;

use App\Exceptions\DiscountManagerException;
use App\Models\AuditLog;
use App\Models\ShopifyDiscountMonitor;
use App\Models\ShopifyDiscountSnapshot;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreOperationalAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ShopifyDiscountMonitorService
{
    public function __construct(
        private ShopifyDiscountService $discounts,
        private StoreOperationalAlertService $alerts,
    ) {}

    public function setEnabled(Store $store, User $actor, string $shopifyDiscountId, bool $enabled): ShopifyDiscountMonitor
    {
        return DB::transaction(function () use ($store, $actor, $shopifyDiscountId, $enabled): ShopifyDiscountMonitor {
            $monitor = ShopifyDiscountMonitor::query()->firstOrNew([
                'store_id' => $store->id,
                'shopify_discount_id' => $shopifyDiscountId,
            ]);
            if ($monitor->exists && (int) $monitor->organization_id !== (int) $store->organization_id) {
                abort(403);
            }
            $before = $monitor->exists ? ['is_enabled' => $monitor->is_enabled] : [];
            $monitor->fill([
                'organization_id' => $store->organization_id,
                'is_enabled' => $enabled,
                'baseline_pending' => $enabled ? true : $monitor->baseline_pending,
                'created_by' => $monitor->created_by ?: $actor->id,
                'updated_by' => $actor->id,
            ])->save();
            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'user_id' => $actor->id,
                'action' => $enabled ? 'shopify_discount_monitor_enabled' : 'shopify_discount_monitor_disabled',
                'subject_type' => ShopifyDiscountMonitor::class,
                'subject_id' => $monitor->id,
                'old_values' => $before,
                'new_values' => ['is_enabled' => $monitor->is_enabled],
                'metadata' => ['shopify_discount_id' => $shopifyDiscountId],
            ]);

            return $monitor;
        });
    }

    /** @return array{checked: int, snapshots: int, alerts: int, failed: int} */
    public function scanStore(Store $store): array
    {
        $result = ['checked' => 0, 'snapshots' => 0, 'alerts' => 0, 'failed' => 0];

        ShopifyDiscountMonitor::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->where('is_enabled', true)
            ->orderBy('id')
            ->eachById(function (ShopifyDiscountMonitor $monitor) use ($store, &$result): void {
                $result['checked']++;
                try {
                    $discount = $this->discounts->detail($store, $monitor->shopify_discount_id);
                    $outcome = $this->process($store, $monitor, $this->normalize($discount));
                    $result['snapshots'] += $outcome['snapshot'] ? 1 : 0;
                    $result['alerts'] += $outcome['alerts'];
                } catch (DiscountManagerException $exception) {
                    if ($exception->errorCode === 'DISCOUNT_NOT_FOUND') {
                        $outcome = $this->process($store, $monitor, $this->missingSnapshot($monitor));
                        $result['snapshots'] += $outcome['snapshot'] ? 1 : 0;
                        $result['alerts'] += $outcome['alerts'];

                        return;
                    }
                    $this->recordFailure($monitor, $exception->getMessage());
                    $result['failed']++;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->recordFailure($monitor, '监控暂时无法读取 Shopify 折扣。');
                    $result['failed']++;
                }
            }, 50);

        return $result;
    }

    /** @param array<string, mixed> $snapshot @return array{snapshot: bool, alerts: int} */
    private function process(Store $store, ShopifyDiscountMonitor $monitor, array $snapshot): array
    {
        $now = CarbonImmutable::now('UTC');
        $hash = $this->hash($snapshot);

        /** @var array{created_snapshot: bool, alert_specs: list<array<string, mixed>>, countdown_state: array<string, mixed>} $prepared */
        $prepared = DB::transaction(function () use ($monitor, $snapshot, $hash, $now): array {
            $locked = ShopifyDiscountMonitor::query()->lockForUpdate()->findOrFail($monitor->id);
            if (! $locked->is_enabled) {
                return ['created_snapshot' => false, 'alert_specs' => [], 'countdown_state' => (array) $locked->countdown_state];
            }
            $previousRecord = $locked->snapshots()->latest('captured_at')->latest('id')->first();
            $previous = is_array($previousRecord?->snapshot) ? $previousRecord->snapshot : null;
            $baseline = $locked->baseline_pending || $previous === null;
            $createdSnapshot = false;
            if (! hash_equals((string) ($locked->last_snapshot_hash ?? ''), $hash)) {
                ShopifyDiscountSnapshot::query()->firstOrCreate(
                    ['monitor_id' => $locked->id, 'content_hash' => $hash],
                    [
                        'organization_id' => $locked->organization_id,
                        'store_id' => $locked->store_id,
                        'shopify_discount_id' => $locked->shopify_discount_id,
                        'snapshot' => $snapshot,
                        'captured_at' => $now,
                    ],
                );
                $createdSnapshot = true;
            }

            $alertSpecs = $baseline || $previous === null ? [] : $this->changeAlerts($previous, $snapshot, $hash);
            [$countdownState, $countdownAlerts] = $this->countdownAlerts(
                (array) $locked->countdown_state,
                $snapshot,
                $now,
                $baseline || ($previous !== null && ($previous['ends_at'] ?? null) !== ($snapshot['ends_at'] ?? null)),
            );
            $alertSpecs = [...$alertSpecs, ...$countdownAlerts];
            $locked->forceFill([
                'baseline_pending' => false,
                'countdown_state' => $countdownState,
                'last_snapshot_hash' => $hash,
                'last_checked_at' => $now,
                'last_seen_at' => ($snapshot['missing'] ?? false) ? $locked->last_seen_at : $now,
                'last_error' => null,
                'last_error_at' => null,
            ])->save();

            return ['created_snapshot' => $createdSnapshot, 'alert_specs' => $alertSpecs, 'countdown_state' => $countdownState];
        });

        $createdAlerts = 0;
        foreach ($prepared['alert_specs'] as $spec) {
            $createdAlerts += (int) $this->alerts->record(
                $store,
                'discount',
                ShopifyDiscountMonitor::class,
                $monitor->id,
                (string) $spec['code'],
                (string) $spec['title'],
                (string) $spec['message'],
                (string) ($spec['severity'] ?? 'warning'),
                (array) ($spec['context'] ?? []),
                $now,
            );
        }

        return ['snapshot' => $prepared['created_snapshot'], 'alerts' => $createdAlerts];
    }

    /** @param array<string, mixed> $previous @param array<string, mixed> $current @return list<array<string, mixed>> */
    private function changeAlerts(array $previous, array $current, string $hash): array
    {
        $changes = [];
        if (($previous['missing'] ?? false) !== true && ($current['missing'] ?? false) === true) {
            $changes[] = '折扣对象：存在 → Shopify 中已找不到';
        }
        if (($previous['ends_at'] ?? null) === null && ($current['ends_at'] ?? null) !== null) {
            $changes[] = '结束时间：无结束时间 → '.$this->dateLabel($current['ends_at']);
        } elseif (filled($previous['ends_at'] ?? null) && filled($current['ends_at'] ?? null)
            && CarbonImmutable::parse($current['ends_at'])->lt(CarbonImmutable::parse($previous['ends_at']))) {
            $changes[] = '结束时间：'.$this->dateLabel($previous['ends_at']).' → '.$this->dateLabel($current['ends_at']);
        }
        if (($previous['title'] ?? '') !== ($current['title'] ?? '')) {
            $changes[] = '标题：'.$this->textLabel($previous['title'] ?? null).' → '.$this->textLabel($current['title'] ?? null);
        }
        if ((array) ($previous['codes'] ?? []) !== (array) ($current['codes'] ?? [])) {
            $changes[] = '折扣码：'.$this->codesLabel($previous['codes'] ?? []).' → '.$this->codesLabel($current['codes'] ?? []);
        }
        $newStatus = strtolower((string) ($current['status'] ?? ''));
        if (($previous['status'] ?? null) !== ($current['status'] ?? null) && in_array($newStatus, ['inactive', 'disabled', 'deactivated', 'expired', 'deleted'], true)) {
            $changes[] = '状态：'.$this->textLabel($previous['status'] ?? null).' → '.$this->textLabel($current['status'] ?? null);
        }
        if ($changes === []) {
            return [];
        }

        return [[
            'code' => 'discount_changed_'.substr($hash, 0, 32),
            'title' => '重点折扣设置发生变化',
            'message' => "Shopify 折扣 ID：{$current['shopify_discount_id']}\n".implode("\n", $changes),
            'severity' => in_array($newStatus, ['inactive', 'disabled', 'deactivated', 'expired', 'deleted'], true) ? 'critical' : 'warning',
            'context' => ['shopify_discount_id' => $current['shopify_discount_id'], 'changes' => $changes],
        ]];
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $snapshot @return array{array<string, mixed>, list<array<string, mixed>>} */
    private function countdownAlerts(array $state, array $snapshot, CarbonImmutable $now, bool $reset): array
    {
        $endsAt = filled($snapshot['ends_at'] ?? null) ? CarbonImmutable::parse($snapshot['ends_at'])->utc() : null;
        $scheduleKey = $endsAt?->toIso8601String();
        if ($reset || ($state['ends_at'] ?? null) !== $scheduleKey) {
            $state = ['ends_at' => $scheduleKey, 'thresholds' => [], 'expired_priority' => null];
            if ($endsAt) {
                $remaining = $now->diffInSeconds($endsAt, false);
                foreach ((array) config('discount_monitoring.thresholds') as $key => $seconds) {
                    if ($remaining <= (int) $seconds) {
                        $state['thresholds'][$key] = 'skipped_baseline';
                    }
                }
                if ($remaining <= 0) {
                    $state['expired_priority'] = 'skipped_baseline';
                }
            }

            return [$state, []];
        }
        if (! $endsAt) {
            return [$state, []];
        }

        $remaining = $now->diffInSeconds($endsAt, false);
        $alerts = [];
        if ($remaining <= 0 && empty($state['expired_priority'])) {
            $state['expired_priority'] = 'sent';
            $alerts[] = $this->countdownSpec($snapshot, 'expired', $endsAt, '重点折扣已经到期但仍在监控列表中', 'critical');

            return [$state, $alerts];
        }
        if ($remaining <= 0) {
            return [$state, []];
        }

        $eligible = collect((array) config('discount_monitoring.thresholds'))
            ->filter(fn (mixed $seconds, string $key): bool => $remaining <= (int) $seconds && empty($state['thresholds'][$key]))
            ->sort();
        if ($eligible->isEmpty()) {
            return [$state, []];
        }
        $selectedKey = (string) $eligible->keys()->first();
        foreach ($eligible as $key => $seconds) {
            $state['thresholds'][$key] = $key === $selectedKey ? 'sent' : 'skipped_late';
        }
        $labels = ['7d' => '7 天', '3d' => '3 天', '24h' => '24 小时'];
        $alerts[] = $this->countdownSpec(
            $snapshot,
            $selectedKey,
            $endsAt,
            '重点折扣将在 '.$labels[$selectedKey].'内到期',
            $selectedKey === '24h' ? 'critical' : 'warning',
        );

        return [$state, $alerts];
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    private function countdownSpec(array $snapshot, string $key, CarbonImmutable $endsAt, string $title, string $severity): array
    {
        $scheduleHash = substr(hash('sha256', $endsAt->toIso8601String()), 0, 24);

        return [
            'code' => "discount_expiry_{$key}_{$scheduleHash}",
            'title' => $title,
            'message' => "Shopify 折扣 ID：{$snapshot['shopify_discount_id']}\n折扣：{$this->textLabel($snapshot['title'] ?? null)}\n折扣码：{$this->codesLabel($snapshot['codes'] ?? [])}\n到期时间：{$this->dateLabel($endsAt)}",
            'severity' => $severity,
            'context' => ['shopify_discount_id' => $snapshot['shopify_discount_id'], 'ends_at' => $endsAt->toIso8601String(), 'threshold' => $key],
        ];
    }

    /** @param array<string, mixed> $discount @return array<string, mixed> */
    private function normalize(array $discount): array
    {
        $codes = collect((array) ($discount['codes'] ?? []))->map(fn (mixed $code): string => trim((string) $code))->filter()->sort()->values()->all();
        $productIds = collect((array) ($discount['product_ids'] ?? []))->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();
        $buysProductIds = collect((array) ($discount['buys_product_ids'] ?? []))->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();
        $getsProductIds = collect((array) ($discount['gets_product_ids'] ?? []))->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();
        $collectionIds = collect((array) ($discount['collection_ids'] ?? []))->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();
        $buysCollectionIds = collect((array) ($discount['buys_collection_ids'] ?? []))->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();
        $getsCollectionIds = collect((array) ($discount['gets_collection_ids'] ?? []))->map(fn (mixed $id): string => (string) $id)->sort()->values()->all();

        return [
            'shopify_discount_id' => (string) $discount['id'],
            'title' => (string) ($discount['title'] ?? ''),
            'codes' => $codes,
            'status' => strtolower((string) ($discount['status'] ?? 'unknown')),
            'starts_at' => $discount['starts_at'] ?? null,
            'ends_at' => $discount['ends_at'] ?? null,
            'discount_value' => [
                'type' => $discount['value_type'] ?? (($discount['kind'] ?? null) === 'bxgy' ? 'bxgy_percentage' : null),
                'value' => $discount['value'] ?? $discount['gets_percentage'] ?? null,
                'currency' => $discount['currency'] ?? null,
                'buys_quantity' => $discount['buys_quantity'] ?? null,
                'gets_quantity' => $discount['gets_quantity'] ?? null,
            ],
            'scope' => [
                'kind' => $discount['kind'] ?? null,
                'summary' => $discount['summary'] ?? null,
                'all_items' => (bool) ($discount['all_items'] ?? false),
                'product_ids' => $productIds,
                'collection_ids' => $collectionIds,
                'buys_product_ids' => $buysProductIds,
                'buys_collection_ids' => $buysCollectionIds,
                'gets_product_ids' => $getsProductIds,
                'gets_collection_ids' => $getsCollectionIds,
            ],
            'shopify_updated_at' => $discount['updated_at'] ?? null,
            'missing' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function missingSnapshot(ShopifyDiscountMonitor $monitor): array
    {
        $previous = $monitor->snapshots()->latest('captured_at')->latest('id')->value('snapshot');

        return [
            ...Arr::only(is_array($previous) ? $previous : [], ['title', 'codes', 'starts_at', 'ends_at', 'discount_value', 'scope', 'shopify_updated_at']),
            'shopify_discount_id' => $monitor->shopify_discount_id,
            'status' => 'deleted',
            'missing' => true,
        ];
    }

    private function recordFailure(ShopifyDiscountMonitor $monitor, string $message): void
    {
        $monitor->forceFill([
            'last_checked_at' => now(),
            'last_error' => Str::limit($message, 1000),
            'last_error_at' => now(),
        ])->save();
    }

    /** @param array<string, mixed> $snapshot */
    private function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function dateLabel(mixed $value): string
    {
        return filled($value) ? CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s').' UTC' : '无';
    }

    private function textLabel(mixed $value): string
    {
        $text = trim((string) $value);

        return $text === '' ? '无' : Str::limit($text, 160);
    }

    private function codesLabel(mixed $codes): string
    {
        $label = collect(is_array($codes) ? $codes : [])->map(fn (mixed $code): string => trim((string) $code))->filter()->implode('、');

        return $label === '' ? '无' : Str::limit($label, 300);
    }
}
