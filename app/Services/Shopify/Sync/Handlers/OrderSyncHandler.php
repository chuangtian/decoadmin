<?php

namespace App\Services\Shopify\Sync\Handlers;

use App\Contracts\Shopify\SyncHandlerInterface;
use App\Exceptions\ShopifyApiException;
use App\Models\ShopifyConnection;
use App\Models\SyncJob;
use App\Services\Shopify\Orders\ShopifyOrderDataService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\Sync\SyncResult;
use Carbon\CarbonImmutable;
use Throwable;

class OrderSyncHandler implements SyncHandlerInterface
{
    private const ORDER_PAGE_SIZE = 50;

    private const LINE_ITEM_PAGE_SIZE = 100;

    /** @var list<string> */
    private const REQUIRED_SCOPES = ['read_orders', 'read_products', 'read_customers', 'read_locations'];

    private const ORDERS_QUERY = <<<'GRAPHQL'
        query SyncOrders($first: Int!, $after: String, $lineItemsFirst: Int!, $query: String) {
          orders(first: $first, after: $after, sortKey: UPDATED_AT, query: $query) {
            nodes {
              id
              name
              email
              displayFinancialStatus
              displayFulfillmentStatus
              sourceName
              app { id name }
              retailLocation { id name }
              currencyCode
              currentTotalPriceSet { shopMoney { amount currencyCode } }
              subtotalPriceSet { shopMoney { amount currencyCode } }
              currentSubtotalPriceSet { shopMoney { amount currencyCode } }
              currentTotalDiscountsSet { shopMoney { amount currencyCode } }
              totalRefundedSet { shopMoney { amount currencyCode } }
              currentShippingPriceSet { shopMoney { amount currencyCode } }
              currentTotalTaxSet { shopMoney { amount currencyCode } }
              test
              processedAt
              cancelledAt
              createdAt
              customer { id }
              lineItems(first: $lineItemsFirst) {
                nodes {
                  id
                  title
                  quantity
                  currentQuantity
                  originalUnitPriceSet { shopMoney { amount currencyCode } }
                  priceAfterAllDiscountsBeforeTaxesSet { shopMoney { amount currencyCode } }
                  product { id }
                  variant { id }
                }
                pageInfo { hasNextPage endCursor }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    private const ORDER_LINE_ITEMS_QUERY = <<<'GRAPHQL'
        query SyncOrderLineItems($orderId: ID!, $first: Int!, $after: String) {
          order(id: $orderId) {
            lineItems(first: $first, after: $after) {
              nodes {
                id
                title
                quantity
                currentQuantity
                originalUnitPriceSet { shopMoney { amount currencyCode } }
                priceAfterAllDiscountsBeforeTaxesSet { shopMoney { amount currencyCode } }
                product { id }
                variant { id }
              }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $client,
        private ShopifyOrderDataService $orders,
    ) {}

    public function type(): string
    {
        return 'orders';
    }

    public function handle(SyncJob $syncJob): SyncResult
    {
        $startedAt = hrtime(true);
        $syncJob->loadMissing([
            'store.shopifyConnection',
            'appInstallation.shopifyConnection',
        ]);
        $store = $syncJob->store;
        $connection = $syncJob->appInstallation?->shopifyConnection
            ?? $store?->shopifyConnection;

        if (! $store || ! $connection || ! in_array($connection->status, ['connected', 'warning'], true)) {
            return SyncResult::failed('当前店铺没有可用的 Shopify 连接。', [
                ['code' => 'shopify_connection_unavailable', 'message' => '请先连接或重新授权 Shopify 店铺。'],
            ]);
        }

        $missingScopes = array_values(array_diff(self::REQUIRED_SCOPES, $connection->scopes ?? []));

        if ($missingScopes !== []) {
            return SyncResult::failed('Shopify 连接缺少订单同步权限，请重新授权。', [
                ['code' => 'missing_shopify_scopes', 'message' => '缺少权限：'.implode(', ', $missingScopes)],
            ], ['required_scopes' => self::REQUIRED_SCOPES]);
        }

        $cursor = $syncJob->cursor;
        $orderCount = $syncJob->processed_items;
        $lineItemCount = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $itemsCreated = 0;
        $itemsUpdated = 0;
        $orderPages = 0;
        $lineItemPages = 0;
        $timeFilter = $this->timeFilter($syncJob);

        do {
            $payload = $this->client->executeSyncQuery($connection, self::ORDERS_QUERY, [
                'first' => self::ORDER_PAGE_SIZE,
                'after' => $cursor,
                'lineItemsFirst' => self::LINE_ITEM_PAGE_SIZE,
                'query' => $timeFilter,
            ]);
            $orders = data_get($payload, 'data.orders');

            if (! is_array($orders)) {
                throw new ShopifyApiException('Shopify 订单 API 未返回有效订单列表。');
            }

            $nodes = $this->nodes($orders);
            $pageInfo = $this->pageInfo($orders);
            $orderPages++;

            foreach ($nodes as $orderNode) {
                $lineItemNodes = $this->orderLineItems($connection, $orderNode, $lineItemPages);
                $saved = $this->orders->upsert($store, $orderNode, $lineItemNodes);
                $saved['created'] ? $createdCount++ : $updatedCount++;
                $itemsCreated += $saved['items_created'];
                $itemsUpdated += $saved['items_updated'];
                $lineItemCount += count($lineItemNodes);
                $orderCount++;
            }

            $hasNextPage = $pageInfo['hasNextPage'];
            $cursor = $hasNextPage ? $pageInfo['endCursor'] : null;
            $syncJob->forceFill([
                'cursor' => $cursor,
                'total_items' => $orderCount,
                'processed_items' => $orderCount,
            ])->save();
        } while ($hasNextPage);

        $durationMs = max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));

        return SyncResult::successful(
            "已同步 {$orderCount} 个 Shopify 订单。",
            $orderCount,
            [
                'framework_only' => false,
                'handler' => static::class,
                'duration_ms' => $durationMs,
                'order_pages' => $orderPages,
                'line_item_pages' => $lineItemPages,
                'line_items_count' => $lineItemCount,
                'orders_created' => $createdCount,
                'orders_updated' => $updatedCount,
                'line_items_created' => $itemsCreated,
                'line_items_updated' => $itemsUpdated,
                'time_filter_applied' => $timeFilter !== null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $orderNode
     * @return list<array<string, mixed>>
     */
    private function orderLineItems(
        ShopifyConnection $connection,
        array $orderNode,
        int &$lineItemPages,
    ): array {
        $lineItems = $orderNode['lineItems'] ?? null;

        if (! is_array($lineItems)) {
            throw new ShopifyApiException('Shopify 订单数据缺少行项目连接结构。');
        }

        $nodes = $this->nodes($lineItems);
        $pageInfo = $this->pageInfo($lineItems);
        $lineItemPages++;

        while ($pageInfo['hasNextPage']) {
            $payload = $this->client->executeSyncQuery($connection, self::ORDER_LINE_ITEMS_QUERY, [
                'orderId' => $orderNode['id'] ?? null,
                'first' => self::LINE_ITEM_PAGE_SIZE,
                'after' => $pageInfo['endCursor'],
            ]);
            $lineItems = data_get($payload, 'data.order.lineItems');

            if (! is_array($lineItems)) {
                throw new ShopifyApiException('Shopify 订单行项目 API 未返回有效数据。');
            }

            array_push($nodes, ...$this->nodes($lineItems));
            $pageInfo = $this->pageInfo($lineItems);
            $lineItemPages++;
        }

        return $nodes;
    }

    private function timeFilter(SyncJob $syncJob): ?string
    {
        $parts = [];

        foreach (['updated_at_from' => '>=', 'updated_at_to' => '<='] as $key => $operator) {
            $value = data_get($syncJob->payload, "filters.{$key}");

            if ($value === null || $value === '') {
                continue;
            }

            if (! is_string($value)) {
                throw new ShopifyApiException("订单同步时间过滤字段 [{$key}] 格式无效。");
            }

            try {
                $timestamp = CarbonImmutable::parse($value)->utc()->toIso8601ZuluString();
            } catch (Throwable) {
                throw new ShopifyApiException("订单同步时间过滤字段 [{$key}] 格式无效。");
            }

            $parts[] = "updated_at:{$operator}'{$timestamp}'";
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<array<string, mixed>>
     */
    private function nodes(array $connection): array
    {
        $nodes = $connection['nodes'] ?? null;

        if (! is_array($nodes) || collect($nodes)->contains(fn ($node) => ! is_array($node))) {
            throw new ShopifyApiException('Shopify GraphQL 连接节点格式无效。');
        }

        return array_values($nodes);
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array{hasNextPage: bool, endCursor: string|null}
     */
    private function pageInfo(array $connection): array
    {
        $pageInfo = $connection['pageInfo'] ?? null;

        if (! is_array($pageInfo) || ! is_bool($pageInfo['hasNextPage'] ?? null)) {
            throw new ShopifyApiException('Shopify GraphQL 分页信息格式无效。');
        }

        $endCursor = $pageInfo['endCursor'] ?? null;

        if ($pageInfo['hasNextPage'] && (! is_string($endCursor) || $endCursor === '')) {
            throw new ShopifyApiException('Shopify GraphQL 分页缺少结束游标。');
        }

        return [
            'hasNextPage' => $pageInfo['hasNextPage'],
            'endCursor' => is_string($endCursor) ? $endCursor : null,
        ];
    }
}
