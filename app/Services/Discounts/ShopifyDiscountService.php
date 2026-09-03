<?php

namespace App\Services\Discounts;

use App\Exceptions\DiscountManagerException;
use App\Exceptions\ShopifyApiException;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class ShopifyDiscountService
{
    private const DISCOUNT_FIELDS = <<<'GRAPHQL'
        __typename
        ... on DiscountCodeBasic {
          title summary status startsAt endsAt discountClasses
          codes(first: 5) { nodes { code } }
          usageLimit asyncUsageCount appliesOncePerCustomer
          combinesWith { orderDiscounts productDiscounts shippingDiscounts }
          context { __typename ... on DiscountBuyerSelectionAll { all } }
          customerGets {
            value {
              ... on DiscountPercentage { percentage }
              ... on DiscountAmount { amount { amount currencyCode } appliesOnEachItem }
            }
            items {
              __typename
              ... on AllDiscountItems { allItems }
              ... on DiscountProducts {
                products(first: 50) { nodes { id title } }
                productVariants(first: 5) { nodes { id title product { id title } } }
              }
              ... on DiscountCollections { collections(first: 5) { nodes { id title } } }
            }
          }
          minimumRequirement {
            __typename
            ... on DiscountMinimumQuantity { greaterThanOrEqualToQuantity }
            ... on DiscountMinimumSubtotal { greaterThanOrEqualToSubtotal { amount currencyCode } }
          }
        }
        ... on DiscountCodeBxgy {
          title summary status startsAt endsAt discountClasses
          codes(first: 5) { nodes { code } }
          usageLimit asyncUsageCount appliesOncePerCustomer usesPerOrderLimit
          combinesWith { orderDiscounts productDiscounts shippingDiscounts }
          context { __typename ... on DiscountBuyerSelectionAll { all } }
          customerBuys {
            value { ... on DiscountQuantity { quantity } ... on DiscountPurchaseAmount { amount } }
            items {
              __typename
              ... on DiscountProducts {
                products(first: 50) { nodes { id title } }
                productVariants(first: 5) { nodes { id title product { id title } } }
              }
              ... on DiscountCollections { collections(first: 5) { nodes { id title } } }
            }
          }
          customerGets {
            value { ... on DiscountOnQuantity { quantity { quantity } effect { ... on DiscountPercentage { percentage } } } }
            items {
              __typename
              ... on DiscountProducts {
                products(first: 50) { nodes { id title } }
                productVariants(first: 5) { nodes { id title product { id title } } }
              }
              ... on DiscountCollections { collections(first: 5) { nodes { id title } } }
            }
          }
        }
        ... on DiscountCodeFreeShipping {
          title summary status startsAt endsAt discountClasses
          codes(first: 5) { nodes { code } }
          usageLimit asyncUsageCount appliesOncePerCustomer
          combinesWith { orderDiscounts productDiscounts shippingDiscounts }
          context { __typename ... on DiscountBuyerSelectionAll { all } }
          destinationSelection { ... on DiscountCountryAll { allCountries } }
          maximumShippingPrice { amount currencyCode }
          minimumRequirement {
            __typename
            ... on DiscountMinimumQuantity { greaterThanOrEqualToQuantity }
            ... on DiscountMinimumSubtotal { greaterThanOrEqualToSubtotal { amount currencyCode } }
          }
        }
        ... on DiscountCodeApp {
          title status startsAt endsAt discountClasses
          codes(first: 5) { nodes { code } }
          asyncUsageCount
        }
        GRAPHQL;

    private const LIST_QUERY = <<<'GRAPHQL'
        query DiscountManagerList($first: Int!, $after: String, $query: String) {
          discountNodes(first: $first, after: $after, query: $query, sortKey: UPDATED_AT, reverse: true) {
            nodes { id discount { DISCOUNT_FIELDS } }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    private const DETAIL_QUERY = <<<'GRAPHQL'
        query DiscountManagerDetail($id: ID!) {
          node(id: $id) { ... on DiscountCodeNode { id codeDiscount { DISCOUNT_FIELDS } } }
        }
        GRAPHQL;

    private const BASIC_CREATE = <<<'GRAPHQL'
        mutation DiscountManagerBasicCreate($input: DiscountCodeBasicInput!) {
          discountCodeBasicCreate(basicCodeDiscount: $input) {
            codeDiscountNode { id }
            userErrors { field code message }
          }
        }
        GRAPHQL;

    private const BASIC_UPDATE = <<<'GRAPHQL'
        mutation DiscountManagerBasicUpdate($id: ID!, $input: DiscountCodeBasicInput!) {
          discountCodeBasicUpdate(id: $id, basicCodeDiscount: $input) {
            codeDiscountNode { id }
            userErrors { field code message }
          }
        }
        GRAPHQL;

    private const BXGY_CREATE = <<<'GRAPHQL'
        mutation DiscountManagerBxgyCreate($input: DiscountCodeBxgyInput!) {
          discountCodeBxgyCreate(bxgyCodeDiscount: $input) {
            codeDiscountNode { id }
            userErrors { field code message }
          }
        }
        GRAPHQL;

    private const BXGY_UPDATE = <<<'GRAPHQL'
        mutation DiscountManagerBxgyUpdate($id: ID!, $input: DiscountCodeBxgyInput!) {
          discountCodeBxgyUpdate(id: $id, bxgyCodeDiscount: $input) {
            codeDiscountNode { id }
            userErrors { field code message }
          }
        }
        GRAPHQL;

    private const FREE_SHIPPING_CREATE = <<<'GRAPHQL'
        mutation DiscountManagerFreeShippingCreate($input: DiscountCodeFreeShippingInput!) {
          discountCodeFreeShippingCreate(freeShippingCodeDiscount: $input) {
            codeDiscountNode { id }
            userErrors { field code message }
          }
        }
        GRAPHQL;

    private const FREE_SHIPPING_UPDATE = <<<'GRAPHQL'
        mutation DiscountManagerFreeShippingUpdate($id: ID!, $input: DiscountCodeFreeShippingInput!) {
          discountCodeFreeShippingUpdate(id: $id, freeShippingCodeDiscount: $input) {
            codeDiscountNode { id }
            userErrors { field code message }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $shopify,
        private DiscountShopifyConnectionService $connections,
    ) {}

    /** @return array{data: list<array<string, mixed>>, next_cursor: string|null} */
    public function listing(Store $store, ?string $status = null, ?string $cursor = null): array
    {
        $query = 'method:code';
        if (in_array($status, ['active', 'scheduled', 'expired'], true)) {
            $query .= ' status:'.$status;
        }
        $payload = $this->query($store, $this->withFields(self::LIST_QUERY), [
            'first' => 50,
            'after' => $cursor ?: null,
            'query' => $query,
        ]);
        $connection = data_get($payload, 'data.discountNodes', []);

        return [
            'data' => collect((array) data_get($connection, 'nodes', []))
                ->map(fn (mixed $node): ?array => is_array($node) ? $this->present($node) : null)
                ->filter()->values()->all(),
            'next_cursor' => data_get($connection, 'pageInfo.hasNextPage') === true
                ? (is_string(data_get($connection, 'pageInfo.endCursor')) ? data_get($connection, 'pageInfo.endCursor') : null)
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Store $store, string $id): array
    {
        $payload = $this->query($store, $this->withFields(self::DETAIL_QUERY), ['id' => $id]);
        $node = data_get($payload, 'data.node');
        if (! is_array($node) || ! is_array($node['codeDiscount'] ?? null)) {
            throw new DiscountManagerException('DISCOUNT_NOT_FOUND', '未找到该折扣，可能已在 Shopify 中删除。', 404);
        }

        return $this->present(['id' => $node['id'], 'discount' => $node['codeDiscount']]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function create(Store $store, User $actor, array $input): array
    {
        $this->connections->forStore($store, write: true);

        return $this->idempotentWrite($store, $actor, 'create', $input, function () use ($store, $actor, $input): array {
            $kind = (string) $input['kind'];
            [$mutation, $operation, $shopifyInput] = match ($kind) {
                'product_amount', 'order_amount' => [self::BASIC_CREATE, 'discountCodeBasicCreate', $this->basicInput($input)],
                'bxgy' => [self::BXGY_CREATE, 'discountCodeBxgyCreate', $this->bxgyInput($input)],
                'free_shipping' => [self::FREE_SHIPPING_CREATE, 'discountCodeFreeShippingCreate', $this->freeShippingInput($input)],
                default => throw new DiscountManagerException('DISCOUNT_KIND_UNSUPPORTED', '不支持该折扣类型。'),
            };
            $id = $this->mutate($store, $mutation, $operation, ['input' => $shopifyInput]);
            $this->audit($store, $actor, 'discount_created', $id, $input);

            return $this->detail($store, $id);
        });
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(Store $store, User $actor, string $id, array $input): array
    {
        $this->connections->forStore($store, write: true);

        return $this->idempotentWrite($store, $actor, 'update:'.$id, $input, function () use ($store, $actor, $id, $input): array {
            $current = $this->detail($store, $id);
            if (($current['editable'] ?? false) !== true || ($current['kind'] ?? null) !== $input['kind']) {
                throw new DiscountManagerException('DISCOUNT_NOT_EDITABLE', '该折扣包含当前页面暂不支持的高级规则，请在 Shopify 中修改。', 409);
            }
            [$mutation, $operation, $shopifyInput] = match ($input['kind']) {
                'product_amount', 'order_amount' => [self::BASIC_UPDATE, 'discountCodeBasicUpdate', $this->basicInput($input, $current)],
                'bxgy' => [self::BXGY_UPDATE, 'discountCodeBxgyUpdate', $this->bxgyInput($input, $current)],
                'free_shipping' => [self::FREE_SHIPPING_UPDATE, 'discountCodeFreeShippingUpdate', $this->freeShippingInput($input)],
                default => throw new DiscountManagerException('DISCOUNT_KIND_UNSUPPORTED', '不支持该折扣类型。'),
            };
            $resultId = $this->mutate($store, $mutation, $operation, ['id' => $id, 'input' => $shopifyInput]);
            $this->audit($store, $actor, 'discount_updated', $resultId, $input, $current);

            return $this->detail($store, $resultId);
        });
    }

    /** @param array<string, mixed> $input @param array<string, mixed>|null $current */
    private function basicInput(array $input, ?array $current = null): array
    {
        $selected = $input['kind'] === 'product_amount' ? $this->gids((array) $input['product_ids']) : [];
        $items = $input['kind'] === 'order_amount'
            ? ['all' => true]
            : ['products' => $this->productChanges($selected, (array) ($current['product_ids'] ?? []))];
        $value = $input['value_type'] === 'percentage'
            ? ['percentage' => (float) $input['value'] / 100]
            : ['discountAmount' => [
                'amount' => $this->decimal($input['value']),
                'appliesOnEachItem' => $input['kind'] === 'product_amount',
            ]];

        return $this->commonInput($input) + [
            'customerGets' => ['value' => $value, 'items' => $items],
        ];
    }

    /** @param array<string, mixed> $input @param array<string, mixed>|null $current */
    private function bxgyInput(array $input, ?array $current = null): array
    {
        return $this->commonInput($input) + [
            'customerBuys' => [
                'value' => ['quantity' => (string) $input['buys_quantity']],
                'items' => ['products' => $this->productChanges(
                    $this->gids((array) $input['buys_product_ids']),
                    (array) ($current['buys_product_ids'] ?? []),
                )],
            ],
            'customerGets' => [
                'value' => ['discountOnQuantity' => [
                    'quantity' => (string) $input['gets_quantity'],
                    'effect' => ['percentage' => (float) $input['gets_percentage'] / 100],
                ]],
                'items' => ['products' => $this->productChanges(
                    $this->gids((array) $input['gets_product_ids']),
                    (array) ($current['gets_product_ids'] ?? []),
                )],
            ],
            'usesPerOrderLimit' => $input['uses_per_order_limit'] ?? null,
        ];
    }

    /** @param array<string, mixed> $input */
    private function freeShippingInput(array $input): array
    {
        return $this->commonInput($input) + ['destination' => ['all' => true]];
    }

    /** @param array<string, mixed> $input */
    private function commonInput(array $input): array
    {
        return array_filter([
            'title' => trim((string) $input['title']),
            'code' => strtoupper(trim((string) $input['code'])),
            'startsAt' => (string) $input['starts_at'],
            'endsAt' => $input['ends_at'] ?: null,
            'context' => ['all' => 'ALL'],
            'appliesOncePerCustomer' => (bool) $input['applies_once_per_customer'],
            'usageLimit' => $input['usage_limit'] ?? null,
            'combinesWith' => [
                'orderDiscounts' => (bool) $input['combine_order'],
                'productDiscounts' => (bool) $input['combine_product'],
                'shippingDiscounts' => (bool) $input['combine_shipping'],
            ],
            'minimumRequirement' => $this->minimumRequirement($input),
        ], fn (mixed $value, string $key): bool => $value !== null || $key === 'endsAt' || $key === 'usageLimit', ARRAY_FILTER_USE_BOTH);
    }

    /** @param array<string, mixed> $input */
    private function minimumRequirement(array $input): ?array
    {
        return match ($input['minimum_type']) {
            'subtotal' => ['subtotal' => ['greaterThanOrEqualToSubtotal' => $this->decimal($input['minimum_subtotal'])]],
            'quantity' => ['quantity' => ['greaterThanOrEqualToQuantity' => (string) $input['minimum_quantity']]],
            default => null,
        };
    }

    /** @param list<string> $selected @param list<string> $current */
    private function productChanges(array $selected, array $current): array
    {
        $current = $this->gids($current);

        return [
            'productsToAdd' => array_values(array_diff($selected, $current)),
            'productsToRemove' => array_values(array_diff($current, $selected)),
        ];
    }

    /** @param array<int, mixed> $ids @return list<string> */
    private function gids(array $ids): array
    {
        return collect($ids)->filter(fn (mixed $id): bool => is_string($id) || is_int($id))
            ->map(function (string|int $id): string {
                $id = (string) $id;

                return str_starts_with($id, 'gid://shopify/Product/') ? $id : "gid://shopify/Product/{$id}";
            })->unique()->values()->all();
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** @param array<string, mixed> $node @return array<string, mixed> */
    private function present(array $node): array
    {
        $discount = (array) ($node['discount'] ?? $node['codeDiscount'] ?? []);
        $type = (string) ($discount['__typename'] ?? 'Unknown');
        $kind = match ($type) {
            'DiscountCodeBasic' => in_array('ORDER', (array) ($discount['discountClasses'] ?? []), true) ? 'order_amount' : 'product_amount',
            'DiscountCodeBxgy' => 'bxgy',
            'DiscountCodeFreeShipping' => 'free_shipping',
            default => 'app',
        };
        $customerGets = (array) ($discount['customerGets'] ?? []);
        $value = (array) ($customerGets['value'] ?? []);
        $amount = data_get($value, 'amount.amount');
        $percentage = $value['percentage'] ?? data_get($value, 'effect.percentage');
        $items = (array) ($customerGets['items'] ?? []);
        $customerAll = data_get($discount, 'context.all') === 'ALL';
        $simpleItems = in_array(($items['__typename'] ?? null), ['AllDiscountItems', 'DiscountProducts'], true)
            && (array) data_get($items, 'productVariants.nodes', []) === [];
        $editable = in_array($kind, ['product_amount', 'order_amount', 'bxgy', 'free_shipping'], true)
            && $customerAll
            && ($kind === 'free_shipping'
                ? data_get($discount, 'destinationSelection.allCountries') === true
                : $simpleItems);
        if ($kind === 'bxgy') {
            $buysItems = (array) data_get($discount, 'customerBuys.items', []);
            $editable = $editable
                && ($buysItems['__typename'] ?? null) === 'DiscountProducts'
                && (array) data_get($buysItems, 'productVariants.nodes', []) === [];
        }
        $minimum = (array) ($discount['minimumRequirement'] ?? []);

        return [
            'id' => (string) ($node['id'] ?? ''),
            'shopify_type' => $type,
            'kind' => $kind,
            'title' => (string) ($discount['title'] ?? '未命名折扣'),
            'summary' => (string) ($discount['summary'] ?? ''),
            'status' => strtolower((string) ($discount['status'] ?? 'unknown')),
            'codes' => collect((array) data_get($discount, 'codes.nodes', []))->pluck('code')->filter()->values()->all(),
            'starts_at' => $discount['startsAt'] ?? null,
            'ends_at' => $discount['endsAt'] ?? null,
            'usage_limit' => $discount['usageLimit'] ?? null,
            'usage_count' => (int) ($discount['asyncUsageCount'] ?? 0),
            'applies_once_per_customer' => (bool) ($discount['appliesOncePerCustomer'] ?? false),
            'combines_with' => Arr::only((array) ($discount['combinesWith'] ?? []), ['orderDiscounts', 'productDiscounts', 'shippingDiscounts']),
            'value_type' => is_numeric($percentage) ? 'percentage' : (is_numeric($amount) ? 'fixed_amount' : null),
            'value' => is_numeric($percentage) ? round((float) $percentage * 100, 4) : (is_numeric($amount) ? (float) $amount : null),
            'currency' => data_get($value, 'amount.currencyCode') ?? data_get($discount, 'maximumShippingPrice.currencyCode'),
            'minimum_type' => match ($minimum['__typename'] ?? null) {
                'DiscountMinimumSubtotal' => 'subtotal',
                'DiscountMinimumQuantity' => 'quantity',
                default => 'none',
            },
            'minimum_subtotal' => data_get($minimum, 'greaterThanOrEqualToSubtotal.amount'),
            'minimum_quantity' => $minimum['greaterThanOrEqualToQuantity'] ?? null,
            'product_ids' => $this->productIds((array) data_get($items, 'products.nodes', [])),
            'buys_product_ids' => $this->productIds((array) data_get($discount, 'customerBuys.items.products.nodes', [])),
            'gets_product_ids' => $this->productIds((array) data_get($discount, 'customerGets.items.products.nodes', [])),
            'buys_quantity' => data_get($discount, 'customerBuys.value.quantity'),
            'gets_quantity' => data_get($discount, 'customerGets.value.quantity.quantity'),
            'gets_percentage' => is_numeric(data_get($discount, 'customerGets.value.effect.percentage'))
                ? round((float) data_get($discount, 'customerGets.value.effect.percentage') * 100, 4)
                : null,
            'uses_per_order_limit' => $discount['usesPerOrderLimit'] ?? null,
            'editable' => $editable,
        ];
    }

    /** @param array<int, mixed> $nodes @return list<string> */
    private function productIds(array $nodes): array
    {
        return collect($nodes)->pluck('id')->filter(fn (mixed $id): bool => is_string($id))
            ->map(fn (string $id): string => str_replace('gid://shopify/Product/', '', $id))
            ->values()->all();
    }

    /** @param array<string, mixed> $variables @return array<string, mixed> */
    private function query(Store $store, string $query, array $variables, bool $write = false): array
    {
        try {
            return $this->shopify->query(
                $this->connections->forStore($store, $write),
                $query,
                $variables,
            );
        } catch (ShopifyApiException) {
            throw new DiscountManagerException('SHOPIFY_ADMIN_API_FAILED', 'Shopify 暂时无法完成折扣请求，请稍后重试。', 502);
        }
    }

    /** @param array<string, mixed> $variables */
    private function mutate(Store $store, string $mutation, string $operation, array $variables): string
    {
        $payload = $this->query($store, $mutation, $variables, write: true);
        $result = data_get($payload, "data.{$operation}");
        $errors = (array) data_get($result, 'userErrors', []);
        $id = data_get($result, 'codeDiscountNode.id');
        if (! is_string($id) || $id === '' || $errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->take(3)->implode('；');
            throw new DiscountManagerException('SHOPIFY_DISCOUNT_WRITE_FAILED', $message ?: 'Shopify 未能保存折扣。', 422);
        }

        return $id;
    }

    private function withFields(string $query): string
    {
        return str_replace('DISCOUNT_FIELDS', self::DISCOUNT_FIELDS, $query);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function idempotentWrite(Store $store, User $actor, string $operation, array $input, callable $action): array
    {
        $key = (string) ($input['idempotency_key'] ?? '');
        $payloadHash = hash('sha256', json_encode(Arr::except($input, ['idempotency_key']), JSON_THROW_ON_ERROR));
        $record = DB::transaction(function () use ($store, $actor, $operation, $key, $payloadHash): object {
            $existing = DB::table('discount_action_idempotencies')
                ->where('store_id', $store->id)
                ->where('user_id', $actor->id)
                ->where('operation', $operation)
                ->where('idempotency_key', $key)
                ->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                    throw new DiscountManagerException('IDEMPOTENCY_KEY_REUSED', '同一个请求标识不能用于不同的折扣内容。', 409);
                }

                $existing->_created_for_request = false;

                return $existing;
            }
            $id = DB::table('discount_action_idempotencies')->insertGetId([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'user_id' => $actor->id,
                'operation' => $operation,
                'idempotency_key' => $key,
                'payload_hash' => $payloadHash,
                'status' => 'processing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $created = DB::table('discount_action_idempotencies')->where('id', $id)->first();
            $created->_created_for_request = true;

            return $created;
        });
        if ($record->status === 'completed' && is_string($record->response)) {
            $response = json_decode($record->response, true);
            if (is_array($response)) {
                return $response;
            }
        }
        if ($record->status === 'processing' && $record->_created_for_request !== true) {
            throw new DiscountManagerException('DISCOUNT_WRITE_IN_PROGRESS', '相同的折扣请求正在处理中，请稍后重试。', 409);
        }

        try {
            $response = $action();
            DB::table('discount_action_idempotencies')->where('id', $record->id)->update([
                'status' => 'completed',
                'shopify_discount_id' => $response['id'] ?? null,
                'response' => json_encode($response, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);

            return $response;
        } catch (Throwable $exception) {
            DB::table('discount_action_idempotencies')->where('id', $record->id)->delete();
            throw $exception;
        }
    }

    /** @param array<string, mixed> $newValues @param array<string, mixed>|null $oldValues */
    private function audit(Store $store, User $actor, string $action, string $id, array $newValues, ?array $oldValues = null): void
    {
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => Store::class,
            'subject_id' => $store->id,
            'old_values' => $oldValues ? Arr::except($oldValues, ['summary']) : null,
            'new_values' => Arr::except($newValues, ['idempotency_key']),
            'metadata' => ['source' => 'discount_manager', 'shopify_discount_id' => $id],
        ]);
    }
}
