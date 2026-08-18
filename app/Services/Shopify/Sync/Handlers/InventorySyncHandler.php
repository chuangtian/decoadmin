<?php

namespace App\Services\Shopify\Sync\Handlers;

use App\Contracts\Shopify\SyncHandlerInterface;
use App\Exceptions\ShopifyApiException;
use App\Models\ShopifyConnection;
use App\Models\SyncJob;
use App\Services\Shopify\Inventory\ShopifyInventoryDataService;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\Sync\SyncResult;

class InventorySyncHandler implements SyncHandlerInterface
{
    private const INVENTORY_ITEM_PAGE_SIZE = 50;

    private const INVENTORY_LEVEL_PAGE_SIZE = 100;

    /** @var list<string> */
    private const REQUIRED_SCOPES = ['read_inventory', 'read_products', 'read_locations'];

    private const INVENTORY_ITEMS_QUERY = <<<'GRAPHQL'
        query SyncInventoryItems($first: Int!, $after: String, $levelsFirst: Int!) {
          inventoryItems(first: $first, after: $after) {
            nodes {
              id
              sku
              tracked
              variants(first: 1) {
                nodes { id }
              }
              inventoryLevels(first: $levelsFirst) {
                nodes {
                  location {
                    id
                    name
                    isActive
                    address {
                      address1
                      address2
                      city
                      province
                      provinceCode
                      country
                      countryCode
                      zip
                      phone
                    }
                  }
                  quantities(names: ["available"]) {
                    name
                    quantity
                    updatedAt
                  }
                }
                pageInfo { hasNextPage endCursor }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GRAPHQL;

    private const INVENTORY_LEVELS_QUERY = <<<'GRAPHQL'
        query SyncInventoryLevels($inventoryItemId: ID!, $first: Int!, $after: String) {
          inventoryItem(id: $inventoryItemId) {
            inventoryLevels(first: $first, after: $after) {
              nodes {
                location {
                  id
                  name
                  isActive
                  address {
                    address1
                    address2
                    city
                    province
                    provinceCode
                    country
                    countryCode
                    zip
                    phone
                  }
                }
                quantities(names: ["available"]) {
                  name
                  quantity
                  updatedAt
                }
              }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $client,
        private ShopifyInventoryDataService $inventory,
    ) {}

    public function type(): string
    {
        return 'inventory';
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
            return SyncResult::failed('Shopify 连接缺少库存同步权限，请重新授权。', [
                ['code' => 'missing_shopify_scopes', 'message' => '缺少权限：'.implode(', ', $missingScopes)],
            ], ['required_scopes' => self::REQUIRED_SCOPES]);
        }

        $cursor = $syncJob->cursor;
        $inventoryItemCount = $syncJob->processed_items;
        $createdCount = 0;
        $updatedCount = 0;
        $levelsCreated = 0;
        $levelsUpdated = 0;
        $locationsCreated = 0;
        $locationsUpdated = 0;
        $inventoryItemPages = 0;
        $inventoryLevelPages = 0;

        do {
            $payload = $this->client->executeSyncQuery($connection, self::INVENTORY_ITEMS_QUERY, [
                'first' => self::INVENTORY_ITEM_PAGE_SIZE,
                'after' => $cursor,
                'levelsFirst' => self::INVENTORY_LEVEL_PAGE_SIZE,
            ]);
            $inventoryItems = data_get($payload, 'data.inventoryItems');

            if (! is_array($inventoryItems)) {
                throw new ShopifyApiException('Shopify 库存项目 API 未返回有效列表。');
            }

            $nodes = $this->nodes($inventoryItems);
            $pageInfo = $this->pageInfo($inventoryItems);
            $inventoryItemPages++;

            foreach ($nodes as $inventoryItemNode) {
                $levelNodes = $this->inventoryLevels($connection, $inventoryItemNode, $inventoryLevelPages);
                $saved = $this->inventory->upsert($store, $inventoryItemNode, $levelNodes);
                $saved['created'] ? $createdCount++ : $updatedCount++;
                $levelsCreated += $saved['levels_created'];
                $levelsUpdated += $saved['levels_updated'];
                $locationsCreated += $saved['locations_created'];
                $locationsUpdated += $saved['locations_updated'];
                $inventoryItemCount++;
            }

            $hasNextPage = $pageInfo['hasNextPage'];
            $cursor = $hasNextPage ? $pageInfo['endCursor'] : null;
            $syncJob->forceFill([
                'cursor' => $cursor,
                'total_items' => $inventoryItemCount,
                'processed_items' => $inventoryItemCount,
            ])->save();
        } while ($hasNextPage);

        $durationMs = max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));

        return SyncResult::successful(
            "已同步 {$inventoryItemCount} 个 Shopify 库存项目。",
            $inventoryItemCount,
            [
                'framework_only' => false,
                'handler' => static::class,
                'duration_ms' => $durationMs,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'inventory_item_pages' => $inventoryItemPages,
                'inventory_level_pages' => $inventoryLevelPages,
                'levels_created' => $levelsCreated,
                'levels_updated' => $levelsUpdated,
                'locations_created' => $locationsCreated,
                'locations_updated' => $locationsUpdated,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $inventoryItemNode
     * @return list<array<string, mixed>>
     */
    private function inventoryLevels(
        ShopifyConnection $connection,
        array $inventoryItemNode,
        int &$inventoryLevelPages,
    ): array {
        $levels = $inventoryItemNode['inventoryLevels'] ?? null;

        if (! is_array($levels)) {
            throw new ShopifyApiException('Shopify 库存项目缺少库存级别连接结构。');
        }

        $nodes = $this->nodes($levels);
        $pageInfo = $this->pageInfo($levels);
        $inventoryLevelPages++;

        while ($pageInfo['hasNextPage']) {
            $payload = $this->client->executeSyncQuery($connection, self::INVENTORY_LEVELS_QUERY, [
                'inventoryItemId' => $inventoryItemNode['id'] ?? null,
                'first' => self::INVENTORY_LEVEL_PAGE_SIZE,
                'after' => $pageInfo['endCursor'],
            ]);
            $levels = data_get($payload, 'data.inventoryItem.inventoryLevels');

            if (! is_array($levels)) {
                throw new ShopifyApiException('Shopify 库存级别 API 未返回有效数据。');
            }

            array_push($nodes, ...$this->nodes($levels));
            $pageInfo = $this->pageInfo($levels);
            $inventoryLevelPages++;
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
