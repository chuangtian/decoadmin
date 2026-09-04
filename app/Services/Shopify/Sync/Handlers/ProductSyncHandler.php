<?php

namespace App\Services\Shopify\Sync\Handlers;

use App\Contracts\Shopify\SyncHandlerInterface;
use App\Exceptions\ShopifyApiException;
use App\Models\ShopifyConnection;
use App\Models\SyncJob;
use App\Services\Shopify\Products\ShopifyCollectionSyncService;
use App\Services\Shopify\Products\ShopifyProductDataService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\Sync\SyncResult;
use Carbon\CarbonImmutable;
use Throwable;

class ProductSyncHandler implements SyncHandlerInterface
{
    private const PRODUCT_PAGE_SIZE = 50;

    private const VARIANT_PAGE_SIZE = 100;

    /** @var list<string> */
    private const REQUIRED_SCOPES = ['read_products', 'read_inventory'];

    private const PRODUCTS_QUERY = <<<'GRAPHQL'
        query SyncProducts($first: Int!, $after: String, $variantsFirst: Int!, $query: String) {
          products(first: $first, after: $after, sortKey: UPDATED_AT, query: $query) {
            nodes {
              id
              title
              handle
              status
              vendor
              productType
              description
              tags
              createdAt
              publishedAt
              onlineStoreUrl
              featuredMedia {
                alt
                ... on MediaImage { image { url width height } }
              }
              variants(first: $variantsFirst) {
                nodes {
                  id
                  title
                  sku
                  price
                  compareAtPrice
                  availableForSale
                  selectedOptions { name value }
                  media(first: 1) {
                    nodes {
                      alt
                      ... on MediaImage { image { url width height } }
                    }
                  }
                  inventoryItem { id }
                }
                pageInfo { hasNextPage endCursor }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    private const PRODUCT_VARIANTS_QUERY = <<<'GRAPHQL'
        query SyncProductVariants($productId: ID!, $first: Int!, $after: String) {
          product(id: $productId) {
            variants(first: $first, after: $after) {
              nodes {
                id
                title
                sku
                price
                compareAtPrice
                availableForSale
                selectedOptions { name value }
                media(first: 1) {
                  nodes {
                    alt
                    ... on MediaImage { image { url width height } }
                  }
                }
                inventoryItem { id }
              }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $client,
        private ShopifyProductDataService $products,
        private ShopifyCollectionSyncService $collections,
    ) {}

    public function type(): string
    {
        return 'products';
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
            return SyncResult::failed('Shopify 连接缺少商品同步权限，请重新授权。', [
                ['code' => 'missing_shopify_scopes', 'message' => '缺少权限：'.implode(', ', $missingScopes)],
            ], ['required_scopes' => self::REQUIRED_SCOPES]);
        }

        $cursor = $syncJob->cursor;
        $productCount = $syncJob->processed_items;
        $variantCount = 0;
        $createdCount = 0;
        $updatedCount = 0;
        $variantsCreated = 0;
        $variantsUpdated = 0;
        $productPages = 0;
        $variantPages = 0;
        $timeFilter = $this->timeFilter($syncJob);

        do {
            $payload = $this->client->executeSyncQuery($connection, self::PRODUCTS_QUERY, [
                'first' => self::PRODUCT_PAGE_SIZE,
                'after' => $cursor,
                'variantsFirst' => self::VARIANT_PAGE_SIZE,
                'query' => $timeFilter,
            ]);
            $products = data_get($payload, 'data.products');

            if (! is_array($products)) {
                throw new ShopifyApiException('Shopify 商品 API 未返回有效商品列表。');
            }

            $nodes = $this->nodes($products);
            $pageInfo = $this->pageInfo($products);
            $productPages++;

            foreach ($nodes as $productNode) {
                $variantNodes = $this->productVariants($connection, $productNode, $variantPages);
                $saved = $this->products->upsert($store, $productNode, $variantNodes);
                $saved['created'] ? $createdCount++ : $updatedCount++;
                $variantsCreated += $saved['variants_created'];
                $variantsUpdated += $saved['variants_updated'];
                $variantCount += count($variantNodes);
                $productCount++;
            }

            $hasNextPage = $pageInfo['hasNextPage'];
            $cursor = $hasNextPage ? $pageInfo['endCursor'] : null;
            $syncJob->forceFill([
                'cursor' => $cursor,
                'total_items' => $productCount,
                'processed_items' => $productCount,
            ])->save();
        } while ($hasNextPage);

        $collectionResult = $this->collections->sync($store, $connection, $timeFilter);

        $durationMs = max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));

        return SyncResult::successful(
            "已同步 {$productCount} 个 Shopify 商品。",
            $productCount,
            [
                'framework_only' => false,
                'handler' => static::class,
                'duration_ms' => $durationMs,
                'product_pages' => $productPages,
                'variant_pages' => $variantPages,
                'variants_count' => $variantCount,
                'products_created' => $createdCount,
                'products_updated' => $updatedCount,
                'variants_created' => $variantsCreated,
                'variants_updated' => $variantsUpdated,
                'time_filter_applied' => $timeFilter !== null,
                ...$collectionResult,
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
                throw new ShopifyApiException("商品同步时间过滤字段 [{$key}] 格式无效。");
            }

            try {
                $timestamp = CarbonImmutable::parse($value)->utc()->toIso8601ZuluString();
            } catch (Throwable) {
                throw new ShopifyApiException("商品同步时间过滤字段 [{$key}] 格式无效。");
            }

            $parts[] = "updated_at:{$operator}'{$timestamp}'";
        }

        return $parts === [] ? null : implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $productNode
     * @return list<array<string, mixed>>
     */
    private function productVariants(
        ShopifyConnection $connection,
        array $productNode,
        int &$variantPages,
    ): array {
        $variants = $productNode['variants'] ?? null;

        if (! is_array($variants)) {
            throw new ShopifyApiException('Shopify 商品数据缺少变体连接结构。');
        }

        $nodes = $this->nodes($variants);
        $pageInfo = $this->pageInfo($variants);
        $variantPages++;

        while ($pageInfo['hasNextPage']) {
            $payload = $this->client->executeSyncQuery($connection, self::PRODUCT_VARIANTS_QUERY, [
                'productId' => $productNode['id'] ?? null,
                'first' => self::VARIANT_PAGE_SIZE,
                'after' => $pageInfo['endCursor'],
            ]);
            $variants = data_get($payload, 'data.product.variants');

            if (! is_array($variants)) {
                throw new ShopifyApiException('Shopify 商品变体 API 未返回有效数据。');
            }

            array_push($nodes, ...$this->nodes($variants));
            $pageInfo = $this->pageInfo($variants);
            $variantPages++;
        }

        return $nodes;
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
