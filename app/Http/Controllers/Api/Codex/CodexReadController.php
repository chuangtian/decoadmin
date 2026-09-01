<?php

namespace App\Http\Controllers\Api\Codex;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Codex\CodexApiAccessService;
use App\Services\Codex\CodexConfigurationStatusService;
use App\Services\DashboardMetricsService;
use App\Services\Shopify\ShopifyDataQueryService;
use App\Services\StoreOperationsQueryService;
use App\Services\SystemStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CodexReadController extends Controller
{
    public function __construct(private CodexApiAccessService $access) {}

    public function stores(Request $request): JsonResponse
    {
        [$user, $organization] = $this->access->context($request, 'stores:read');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $storeIds = $this->access->accessibleStoreIds($user, $organization, 'store.view');
        $stores = $organization->stores()
            ->whereIn('stores.id', $storeIds)
            ->where('stores.status', 'active')
            ->when($filters['search'] ?? null, fn ($query, string $search) => $query->where(fn ($query) => $query
                ->where('stores.name', 'like', "%{$search}%")
                ->orWhere('stores.shopify_domain', 'like', "%{$search}%")))
            ->with([
                'shopifyConnection' => fn ($query) => $query->select([
                    'shopify_connections.id', 'shopify_connections.store_id', 'shopify_connections.status',
                    'shopify_connections.last_verified_at',
                ]),
                'latestSyncJob' => fn ($query) => $query->select([
                    'sync_jobs.id', 'sync_jobs.store_id', 'sync_jobs.status',
                    'sync_jobs.finished_at', 'sync_jobs.completed_at',
                ]),
            ])
            ->orderBy('stores.name')
            ->paginate((int) ($filters['per_page'] ?? 25), [
                'stores.id', 'stores.organization_id', 'stores.name', 'stores.shopify_domain',
                'stores.status', 'stores.timezone', 'stores.currency',
            ]);
        $items = $stores->getCollection()->map(fn (Store $store): array => [
            'id' => $store->getKey(),
            'name' => $store->name,
            'shopify_domain' => $store->shopify_domain,
            'status' => $store->status,
            'timezone' => $store->timezone,
            'currency' => $store->currency,
            'connection_status' => $store->shopifyConnection?->status ?? 'disconnected',
            'last_verified_at' => $store->shopifyConnection?->last_verified_at?->toIso8601String(),
            'last_sync' => $store->latestSyncJob ? [
                'status' => $store->latestSyncJob->status,
                'at' => ($store->latestSyncJob->finished_at ?? $store->latestSyncJob->completed_at)?->toIso8601String(),
            ] : null,
        ])->values()->all();

        return $this->response([
            'items' => $items,
            'pagination' => [
                'page' => $stores->currentPage(),
                'per_page' => $stores->perPage(),
                'total' => $stores->total(),
                'last_page' => $stores->lastPage(),
            ],
        ]);
    }

    public function dashboard(Request $request, Store $store, DashboardMetricsService $metrics): JsonResponse
    {
        [$user, $organization] = $this->access->store($request, $store, 'dashboard:read', 'orders.view');
        $filters = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:366'],
            'include_test' => ['nullable', 'boolean'],
            'include_cancelled' => ['nullable', 'boolean'],
            'comparison' => ['nullable', 'in:none,previous,year,year_weekday'],
        ]);
        $dashboard = $metrics->forStore($store, $organization, $user, $filters ?: ['days' => 30]);

        return $this->response([
            'store' => $dashboard['store'],
            'summary' => $dashboard['summary'],
            'operations' => $dashboard['operations'],
            'sales_trend' => $dashboard['sales_trend'],
            'analytics' => [
                'period' => data_get($dashboard, 'analytics.period'),
                'comparison' => data_get($dashboard, 'analytics.comparison'),
                'summary' => data_get($dashboard, 'analytics.summary'),
                'comparisons' => data_get($dashboard, 'analytics.comparisons'),
                'trend' => data_get($dashboard, 'analytics.trend'),
                'top_products' => data_get($dashboard, 'analytics.top_products'),
                'low_stock' => data_get($dashboard, 'analytics.low_stock'),
                'data_source' => [
                    'primary' => data_get($dashboard, 'analytics.data_source.primary'),
                    'semantic_mode' => data_get($dashboard, 'analytics.data_source.semantic_mode'),
                    'notice' => data_get($dashboard, 'analytics.data_source.notice'),
                    'pending' => (bool) data_get($dashboard, 'analytics.data_source.pending', false),
                    'comparison_pending' => (bool) data_get($dashboard, 'analytics.data_source.comparison_pending', false),
                ],
            ],
        ]);
    }

    public function orders(Request $request, Store $store, ShopifyDataQueryService $queries): JsonResponse
    {
        $this->access->store($request, $store, 'orders:read', 'orders.view');
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'financial_status' => ['nullable', 'string', 'max:40'],
            'fulfillment_status' => ['nullable', 'in:fulfilled,partial,restocked,unfulfilled'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $orders = $queries->orders(
            $store,
            $filters['search'] ?? null,
            $filters['financial_status'] ?? null,
            $filters['fulfillment_status'] ?? null,
        );

        return $this->response([
            'items' => collect($orders->items())->map(fn ($order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'financial_status' => $order->financial_status,
                'fulfillment_status' => $order->fulfillment_status,
                'currency' => $order->currency,
                'total_price' => (string) $order->total_price,
                'net_sales' => (string) $order->net_sales,
                'items_count' => (int) $order->items_count,
                'processed_at' => $order->processed_at?->toIso8601String() ?? $order->created_at_shopify?->toIso8601String(),
            ])->values()->all(),
            'pagination' => [
                'page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ],
        ]);
    }

    public function operations(Request $request, Store $store, StoreOperationsQueryService $operations): JsonResponse
    {
        [$user, $organization] = $this->access->scopedStore($request, $store, 'operations:read');
        $data = $operations->forStore($user, $organization, $store);

        return $this->response([
            'capabilities' => $data['capabilities'],
            'sync' => $data['sync'],
            'webhooks' => [
                'summary' => data_get($data, 'webhooks.summary'),
                'events' => collect(data_get($data, 'webhooks.events', []))->map(fn (array $event): array => [
                    'id' => $event['id'],
                    'topic' => $event['topic'],
                    'status' => $event['status'],
                    'attempts' => $event['attempts'],
                    'received_at' => $event['received_at'],
                    'processed_at' => $event['processed_at'],
                    'can_retry' => $event['can_retry'],
                ])->values()->all(),
            ],
            'logs' => $data['logs'],
        ]);
    }

    public function configurationStatus(Request $request, Store $store, CodexConfigurationStatusService $configuration): JsonResponse
    {
        [$user, $organization] = $this->access->store($request, $store, 'configuration:read', 'store.view');
        abort_unless($user->hasPermission('system.settings.view', $organization, $store), 403, '当前账号无权查看配置状态。');

        return $this->response($configuration->forStore($store->load(['shopifyConnection'])));
    }

    public function systemStatus(Request $request, SystemStatusService $systemStatus): JsonResponse
    {
        [$user, $organization] = $this->access->context($request, 'system:read');
        abort_unless($user->hasPermission('system.health.view', $organization), 403, '当前账号无权查看系统状态。');

        return $this->response($systemStatus->snapshot($user, $organization));
    }

    private function response(mixed $data): JsonResponse
    {
        return response()->json([
            'schema_version' => 'decoadmin-codex-v1',
            'data' => $data,
            'meta' => ['generated_at' => now()->toIso8601String()],
        ]);
    }
}
