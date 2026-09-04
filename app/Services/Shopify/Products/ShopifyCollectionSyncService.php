<?php

namespace App\Services\Shopify\Products;

use App\Exceptions\ShopifyApiException;
use App\Models\ProductCollection;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;
use Illuminate\Support\Str;

class ShopifyCollectionSyncService
{
    private const COLLECTION_PAGE_SIZE = 50;

    private const PRODUCT_PAGE_SIZE = 100;

    private const COLLECTIONS_QUERY = <<<'GRAPHQL'
        query SyncCollections($first: Int!, $after: String, $productsFirst: Int!, $query: String) {
          collections(first: $first, after: $after, sortKey: UPDATED_AT, query: $query) {
            nodes {
              id
              title
              handle
              updatedAt
              sortOrder
              products(first: $productsFirst) {
                nodes { id }
                pageInfo { hasNextPage endCursor }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    private const COLLECTION_PRODUCTS_QUERY = <<<'GRAPHQL'
        query SyncCollection($id: ID!, $first: Int!, $after: String) {
          collection(id: $id) {
            id
            title
            handle
            updatedAt
            sortOrder
            products(first: $first, after: $after) {
              nodes { id }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $client,
        private ShopifyCollectionDataService $collections,
    ) {}

    /**
     * @return array{
     *   collections_count: int,
     *   collections_created: int,
     *   collections_updated: int,
     *   collection_pages: int,
     *   membership_pages: int,
     *   memberships_count: int,
     *   unresolved_memberships: int,
     *   memberships_removed: int,
     *   collections_removed: int
     * }
     */
    public function sync(Store $store, ShopifyConnection $connection, ?string $query = null): array
    {
        $syncBatch = (string) Str::uuid();
        $cursor = null;
        $result = $this->emptyResult();

        do {
            $payload = $this->client->executeSyncQuery($connection, self::COLLECTIONS_QUERY, [
                'first' => self::COLLECTION_PAGE_SIZE,
                'after' => $cursor,
                'productsFirst' => self::PRODUCT_PAGE_SIZE,
                'query' => $query,
            ]);
            $collectionConnection = data_get($payload, 'data.collections');

            if (! is_array($collectionConnection)) {
                throw new ShopifyApiException('Shopify Collection API 未返回有效列表。');
            }

            foreach ($this->nodes($collectionConnection, 'Collection') as $collectionNode) {
                $this->syncNode($store, $connection, $collectionNode, $syncBatch, $result);
            }

            $pageInfo = $this->pageInfo($collectionConnection, 'Collection');
            $result['collection_pages']++;
            $cursor = $pageInfo['hasNextPage'] ? $pageInfo['endCursor'] : null;
        } while ($pageInfo['hasNextPage']);

        if ($query === null) {
            $result['collections_removed'] = $this->collections->prune($store, $syncBatch);
        }

        return $result;
    }

    /** @param array<string, mixed> $collectionNode
     * @param  array<string, int>  $result
     */
    private function syncNode(
        Store $store,
        ShopifyConnection $connection,
        array $collectionNode,
        string $syncBatch,
        array &$result,
    ): void {
        $saved = $this->collections->upsert($store, $collectionNode, $syncBatch);
        $saved['created'] ? $result['collections_created']++ : $result['collections_updated']++;
        $result['collections_count']++;
        $membershipConnection = $collectionNode['products'] ?? null;

        if (! is_array($membershipConnection)) {
            throw new ShopifyApiException('Shopify Collection 数据缺少商品连接结构。');
        }

        $this->syncMembershipPage($store, $saved['collection'], $membershipConnection, $syncBatch, $result);
        $pageInfo = $this->pageInfo($membershipConnection, 'Collection 商品');

        while ($pageInfo['hasNextPage']) {
            $payload = $this->client->executeSyncQuery($connection, self::COLLECTION_PRODUCTS_QUERY, [
                'id' => $collectionNode['id'] ?? null,
                'first' => self::PRODUCT_PAGE_SIZE,
                'after' => $pageInfo['endCursor'],
            ]);
            $membershipConnection = data_get($payload, 'data.collection.products');

            if (! is_array($membershipConnection)) {
                throw new ShopifyApiException('Shopify Collection 商品 API 未返回有效数据。');
            }

            $this->syncMembershipPage($store, $saved['collection'], $membershipConnection, $syncBatch, $result);
            $pageInfo = $this->pageInfo($membershipConnection, 'Collection 商品');
        }

        $result['memberships_removed'] += $this->collections->completeMembershipSync($saved['collection'], $syncBatch);
    }

    /** @param array<string, mixed> $connection
     * @param  array<string, int>  $result
     */
    private function syncMembershipPage(
        Store $store,
        ProductCollection $collection,
        array $connection,
        string $syncBatch,
        array &$result,
    ): void {
        $gids = collect($this->nodes($connection, 'Collection 商品'))
            ->map(fn (array $node): mixed => $node['id'] ?? null)
            ->all();
        $saved = $this->collections->syncMembershipPage($store, $collection, $gids, $syncBatch);
        $result['membership_pages']++;
        $result['memberships_count'] += $saved['members'];
        $result['unresolved_memberships'] += $saved['unresolved'];
    }

    /** @param array<string, mixed> $connection
     * @return list<array<string, mixed>>
     */
    private function nodes(array $connection, string $resource): array
    {
        $nodes = $connection['nodes'] ?? null;

        if (! is_array($nodes) || collect($nodes)->contains(fn ($node) => ! is_array($node))) {
            throw new ShopifyApiException("Shopify {$resource} 连接节点格式无效。");
        }

        return array_values($nodes);
    }

    /** @param array<string, mixed> $connection
     * @return array{hasNextPage: bool, endCursor: string|null}
     */
    private function pageInfo(array $connection, string $resource): array
    {
        $pageInfo = $connection['pageInfo'] ?? null;

        if (! is_array($pageInfo) || ! is_bool($pageInfo['hasNextPage'] ?? null)) {
            throw new ShopifyApiException("Shopify {$resource} 分页信息格式无效。");
        }

        $endCursor = $pageInfo['endCursor'] ?? null;

        if ($pageInfo['hasNextPage'] && (! is_string($endCursor) || $endCursor === '')) {
            throw new ShopifyApiException("Shopify {$resource} 分页缺少结束游标。");
        }

        return [
            'hasNextPage' => $pageInfo['hasNextPage'],
            'endCursor' => is_string($endCursor) ? $endCursor : null,
        ];
    }

    /** @return array<string, int> */
    private function emptyResult(): array
    {
        return [
            'collections_count' => 0,
            'collections_created' => 0,
            'collections_updated' => 0,
            'collection_pages' => 0,
            'membership_pages' => 0,
            'memberships_count' => 0,
            'unresolved_memberships' => 0,
            'memberships_removed' => 0,
            'collections_removed' => 0,
        ];
    }
}
