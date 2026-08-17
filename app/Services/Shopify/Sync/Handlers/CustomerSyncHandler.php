<?php

namespace App\Services\Shopify\Sync\Handlers;

use App\Contracts\Shopify\SyncHandlerInterface;
use App\Exceptions\ShopifyApiException;
use App\Models\SyncJob;
use App\Services\Shopify\Customers\ShopifyCustomerDataService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\Sync\SyncResult;
use Carbon\CarbonImmutable;
use Throwable;

class CustomerSyncHandler implements SyncHandlerInterface
{
    private const CUSTOMER_PAGE_SIZE = 100;

    /** @var list<string> */
    private const REQUIRED_SCOPES = ['read_customers'];

    private const CUSTOMERS_QUERY = <<<'GRAPHQL'
        query SyncCustomers($first: Int!, $after: String, $query: String) {
          customers(first: $first, after: $after, sortKey: ID, query: $query) {
            nodes {
              id
              firstName
              lastName
              defaultEmailAddress { emailAddress }
              defaultPhoneNumber { phoneNumber }
              state
              verifiedEmail
              numberOfOrders
              amountSpent { amount currencyCode }
              createdAt
              updatedAt
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $client,
        private ShopifyCustomerDataService $customers,
    ) {}

    public function type(): string
    {
        return 'customers';
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
            return SyncResult::failed('当前店铺没有可用的 Shopify Connection。', [
                ['code' => 'shopify_connection_unavailable', 'message' => '请先连接或重新授权 Shopify 店铺。'],
            ]);
        }

        $missingScopes = array_values(array_diff(self::REQUIRED_SCOPES, $connection->scopes ?? []));

        if ($missingScopes !== []) {
            return SyncResult::failed('Shopify Connection 缺少客户同步权限，请重新授权。', [
                ['code' => 'missing_shopify_scopes', 'message' => '缺少权限：'.implode(', ', $missingScopes)],
            ], ['required_scopes' => self::REQUIRED_SCOPES]);
        }

        $cursor = $syncJob->cursor;
        $customerCount = $syncJob->processed_items;
        $createdCount = 0;
        $updatedCount = 0;
        $customerPages = 0;
        $currencies = [];
        $timeFilter = $this->timeFilter($syncJob);

        do {
            $payload = $this->client->executeSyncQuery($connection, self::CUSTOMERS_QUERY, [
                'first' => self::CUSTOMER_PAGE_SIZE,
                'after' => $cursor,
                'query' => $timeFilter,
            ]);
            $customers = data_get($payload, 'data.customers');

            if (! is_array($customers)) {
                throw new ShopifyApiException('Shopify Customers API 未返回有效客户列表。');
            }

            $nodes = $this->nodes($customers);
            $pageInfo = $this->pageInfo($customers);
            $customerPages++;

            foreach ($nodes as $customerNode) {
                $saved = $this->customers->upsert($store, $customerNode);
                $saved['created'] ? $createdCount++ : $updatedCount++;
                $currencies[$saved['currency']] = true;
                $customerCount++;
            }

            $hasNextPage = $pageInfo['hasNextPage'];
            $cursor = $hasNextPage ? $pageInfo['endCursor'] : null;
            $syncJob->forceFill([
                'cursor' => $cursor,
                'total_items' => $customerCount,
                'processed_items' => $customerCount,
            ])->save();
        } while ($hasNextPage);

        $durationMs = max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));

        return SyncResult::successful(
            "已同步 {$customerCount} 个 Shopify 客户。",
            $customerCount,
            [
                'framework_only' => false,
                'handler' => static::class,
                'duration_ms' => $durationMs,
                'customer_pages' => $customerPages,
                'customers_created' => $createdCount,
                'customers_updated' => $updatedCount,
                'currencies' => array_keys($currencies),
                'time_filter_applied' => $timeFilter !== null,
            ],
        );
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
                throw new ShopifyApiException("客户同步时间过滤字段 [{$key}] 格式无效。");
            }

            try {
                $timestamp = CarbonImmutable::parse($value)->utc()->toIso8601ZuluString();
            } catch (Throwable) {
                throw new ShopifyApiException("客户同步时间过滤字段 [{$key}] 格式无效。");
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
            throw new ShopifyApiException('Shopify GraphQL Connection Nodes 格式无效。');
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
            throw new ShopifyApiException('Shopify GraphQL Connection PageInfo 格式无效。');
        }

        $endCursor = $pageInfo['endCursor'] ?? null;

        if ($pageInfo['hasNextPage'] && (! is_string($endCursor) || $endCursor === '')) {
            throw new ShopifyApiException('Shopify GraphQL 分页缺少 End Cursor。');
        }

        return [
            'hasNextPage' => $pageInfo['hasNextPage'],
            'endCursor' => is_string($endCursor) ? $endCursor : null,
        ];
    }
}
