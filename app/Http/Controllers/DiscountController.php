<?php

namespace App\Http\Controllers;

use App\Exceptions\DiscountManagerException;
use App\Models\Product;
use App\Models\ShopifyDiscountMonitor;
use App\Models\Store;
use App\Services\Discounts\DiscountShopifyConnectionService;
use App\Services\Discounts\ShopifyDiscountMonitorService;
use App\Services\Discounts\ShopifyDiscountService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DiscountController extends Controller
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentStore $currentStore,
        private ShopifyDiscountService $discounts,
        private DiscountShopifyConnectionService $connections,
        private ShopifyDiscountMonitorService $monitors,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['active', 'scheduled', 'expired'])],
            'cursor' => ['nullable', 'string', 'max:500'],
        ]);
        $store = $this->scopedStore($request, 'discounts.view');
        $listing = ['data' => [], 'next_cursor' => null];
        $connection = ['ready' => false, 'can_write' => false, 'code' => null, 'message' => null];
        try {
            $listing = $this->discounts->listing($store, $filters['status'] ?? null, $filters['cursor'] ?? null);
            $connection['ready'] = true;
            $this->connections->forStore($store, write: true);
            $connection['can_write'] = true;
        } catch (DiscountManagerException $exception) {
            $connection['code'] = $exception->errorCode;
            $connection['message'] = $exception->getMessage();
        }

        return Inertia::render('Discounts/Index', [
            'discounts' => $listing,
            'monitors' => ShopifyDiscountMonitor::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->whereIn('shopify_discount_id', collect($listing['data'])->pluck('id')->all())
                ->get(['shopify_discount_id', 'is_enabled', 'last_checked_at', 'last_error'])
                ->map(fn (ShopifyDiscountMonitor $monitor): array => [
                    'shopify_discount_id' => $monitor->shopify_discount_id,
                    'is_enabled' => $monitor->is_enabled,
                    'last_checked_at' => $monitor->last_checked_at?->toIso8601String(),
                    'last_error' => $monitor->last_error,
                ])->values()->all(),
            'filters' => ['status' => $filters['status'] ?? ''],
            'connection' => $connection,
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'currency' => $store->currency ?: 'USD',
                'timezone' => $store->timezone ?: 'UTC',
            ],
            'products' => Product::query()
                ->forOrganization((int) $store->organization_id)
                ->forStore($store)
                ->where('status', 'active')
                ->orderBy('title')
                ->limit(200)
                ->get(['shopify_product_id', 'title', 'featured_image_url'])
                ->map(fn (Product $product): array => [
                    'id' => (string) $product->shopify_product_id,
                    'title' => $product->title,
                    'image' => $product->featured_image_url,
                ])->all(),
            'permissions' => [
                'connect' => $request->user()->can('connect', $store),
                'manage' => $request->user()->hasPermission(
                    'discounts.manage',
                    $this->currentOrganization->require(),
                    $store,
                ),
            ],
        ]);
    }

    public function show(Request $request, string $discountId): JsonResponse
    {
        $store = $this->scopedStore($request, 'discounts.view');

        return $this->run(fn (): array => $this->discounts->detail($store, $this->gid($discountId)));
    }

    public function store(Request $request): JsonResponse
    {
        $store = $this->scopedStore($request, 'discounts.manage');
        $values = $this->validated($request, $store);

        return $this->run(fn (): array => $this->discounts->create($store, $request->user(), $values), 201);
    }

    public function update(Request $request, string $discountId): JsonResponse
    {
        $store = $this->scopedStore($request, 'discounts.manage');
        $values = $this->validated($request, $store);

        return $this->run(fn (): array => $this->discounts->update(
            $store,
            $request->user(),
            $this->gid($discountId),
            $values,
        ));
    }

    public function updateMonitor(Request $request, string $discountId): JsonResponse
    {
        $store = $this->scopedStore($request, 'discounts.manage');
        $values = $request->validate([
            'store_id' => ['required', 'integer'],
            'enabled' => ['required', 'boolean'],
        ]);
        abort_unless((int) $values['store_id'] === (int) $store->id, 409, '当前店铺已切换，请刷新折扣页面后重试。');
        $monitor = $this->monitors->setEnabled($store, $request->user(), $this->gid($discountId), (bool) $values['enabled']);

        return response()->json(['data' => [
            'shopify_discount_id' => $monitor->shopify_discount_id,
            'is_enabled' => $monitor->is_enabled,
            'last_checked_at' => $monitor->last_checked_at?->toIso8601String(),
            'last_error' => $monitor->last_error,
        ]]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Store $store): array
    {
        $values = $request->validate([
            'store_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'uuid'],
            'kind' => ['required', Rule::in(['product_amount', 'order_amount', 'bxgy', 'free_shipping'])],
            'title' => ['required', 'string', 'max:120', 'regex:/\S/u'],
            'code' => ['required', 'string', 'min:2', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'applies_once_per_customer' => ['required', 'boolean'],
            'combine_order' => ['required', 'boolean'],
            'combine_product' => ['required', 'boolean'],
            'combine_shipping' => ['required', 'boolean'],
            'minimum_type' => ['required', Rule::in(['none', 'subtotal', 'quantity'])],
            'minimum_subtotal' => ['nullable', 'required_if:minimum_type,subtotal', 'numeric', 'min:0.01', 'max:999999999.99'],
            'minimum_quantity' => ['nullable', 'required_if:minimum_type,quantity', 'integer', 'min:1', 'max:2147483647'],
            'value_type' => ['nullable', 'required_unless:kind,bxgy,free_shipping', Rule::in(['percentage', 'fixed_amount'])],
            'value' => ['nullable', 'required_unless:kind,bxgy,free_shipping', 'numeric', 'min:0.01', 'max:999999999.99'],
            'product_ids' => ['exclude_unless:kind,product_amount', 'array', 'min:1', 'max:50', 'required_if:kind,product_amount'],
            'product_ids.*' => ['string', 'distinct', 'regex:/^\d+$/'],
            'buys_product_ids' => ['exclude_unless:kind,bxgy', 'array', 'min:1', 'max:50', 'required_if:kind,bxgy'],
            'buys_product_ids.*' => ['string', 'distinct', 'regex:/^\d+$/'],
            'gets_product_ids' => ['exclude_unless:kind,bxgy', 'array', 'min:1', 'max:50', 'required_if:kind,bxgy'],
            'gets_product_ids.*' => ['string', 'distinct', 'regex:/^\d+$/'],
            'buys_quantity' => ['nullable', 'required_if:kind,bxgy', 'integer', 'min:1', 'max:1000'],
            'gets_quantity' => ['nullable', 'required_if:kind,bxgy', 'integer', 'min:1', 'max:1000'],
            'gets_percentage' => ['nullable', 'required_if:kind,bxgy', 'numeric', 'min:0.01', 'max:100'],
            'uses_per_order_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        abort_unless((int) $values['store_id'] === (int) $store->id, 409, '当前店铺已切换，请刷新折扣页面后重试。');
        if (($values['value_type'] ?? null) === 'percentage' && (float) ($values['value'] ?? 0) > 100) {
            abort(422, '百分比折扣不能超过 100%。');
        }
        $productFields = match ($values['kind']) {
            'product_amount' => ['product_ids'],
            'bxgy' => ['buys_product_ids', 'gets_product_ids'],
            default => [],
        };
        $allowedProductIds = Product::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore($store)
            ->whereIn('shopify_product_id', collect($productFields)->flatMap(fn (string $field): array => $values[$field] ?? [])->all())
            ->pluck('shopify_product_id')->map(fn (mixed $id): string => (string) $id)->all();
        foreach ($productFields as $field) {
            foreach ($values[$field] ?? [] as $id) {
                abort_unless(in_array((string) $id, $allowedProductIds, true), 422, '所选商品不属于当前店铺。');
            }
        }
        $timezone = $store->timezone ?: 'UTC';
        $values['starts_at'] = CarbonImmutable::parse($values['starts_at'], $timezone)->utc()->toIso8601String();
        $values['ends_at'] = filled($values['ends_at'] ?? null)
            ? CarbonImmutable::parse($values['ends_at'], $timezone)->utc()->toIso8601String()
            : null;

        return $values;
    }

    private function scopedStore(Request $request, string $permission): Store
    {
        $organization = $this->currentOrganization->require();
        $store = $this->currentStore->require();
        abort_unless((int) $store->organization_id === (int) $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission($permission, $organization, $store), 403);

        return $store;
    }

    private function gid(string $discountId): string
    {
        abort_unless(preg_match('/^\d+$/', $discountId) === 1, 404);

        return "gid://shopify/DiscountCodeNode/{$discountId}";
    }

    private function run(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['data' => $action()], $status);
        } catch (DiscountManagerException $exception) {
            return response()->json(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]], $exception->statusCode);
        }
    }
}
