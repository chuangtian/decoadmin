<?php

namespace App\Services\Shopify\Products;

use App\Exceptions\ShopifyApiException;
use App\Models\Store;
use App\Services\Shopify\ShopifyGraphQLClient;

class ShopifyProductMonitorReader
{
    public const PRODUCT_QUERY = <<<'GRAPHQL'
        query MonitorProduct($id: ID!, $after: String) {
          product(id: $id) {
            id title status publishedAt updatedAt
            variants(first: 50, after: $after) {
              nodes { id title sku inventoryItem { id tracked } }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GRAPHQL;

    public const INVENTORY_QUERY = <<<'GRAPHQL'
        query MonitorInventory($id: ID!, $after: String) {
          inventoryItem(id: $id) {
            inventoryLevels(first: 100, after: $after) {
              nodes { location { id name isActive } quantities(names: ["available"]) { name quantity } }
              pageInfo { hasNextPage endCursor }
            }
          }
        }
        GRAPHQL;

    public function __construct(private ShopifyGraphQLClient $client) {}

    public function read(Store $store, string $id): array
    {
        $connection = $store->shopifyConnection;
        if ($store->status !== 'active' || $store->organization?->status !== 'active'
            || ! $connection || (int) $connection->store_id !== (int) $store->id
            || strtolower($connection->shop_domain) !== strtolower($store->shopify_domain)
            || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $store->shopify_domain)
            || ! in_array($connection->status, ['connected', 'warning'], true)) {
            throw new ShopifyApiException('当前店铺 Shopify 连接不可用。');
        }
        foreach (['products', 'inventory', 'locations'] as $scope) {
            if (! array_intersect(['read_'.$scope, 'write_'.$scope], $connection->scopes ?? [])) {
                throw new ShopifyApiException('监控缺少只读权限。');
            }
        }
        if (! preg_match('/^[0-9]+$/', $id)) {
            throw new ShopifyApiException('商品 ID 无效。');
        }
        $cursor = null;
        $snapshot = null;
        $seen = [];
        do {
            $data = $this->client->executeSyncQuery($connection, self::PRODUCT_QUERY, ['id' => 'gid://shopify/Product/'.$id, 'after' => $cursor]);
            if (! array_key_exists('product', $data['data'] ?? [])) {
                throw new ShopifyApiException('商品响应不完整。');
            }
            $product = $data['data']['product'];
            if ($product === null && $cursor === null) {
                return ['id' => $id, 'missing' => true];
            }
            if (! is_array($product) || ($product['id'] ?? null) !== 'gid://shopify/Product/'.$id
                || ! isset($product['title'], $product['status'], $product['updatedAt']) || ! array_key_exists('publishedAt', $product)) {
                throw new ShopifyApiException('商品响应不完整。');
            }
            $snapshot ??= ['id' => $id, 'title' => $product['title'], 'status' => $product['status'],
                'published' => $product['publishedAt'] !== null, 'updated_at' => $product['updatedAt'], 'missing' => false, 'variants' => []];
            if ($snapshot['updated_at'] !== $product['updatedAt']) {
                throw new ShopifyApiException('读取期间商品发生变化，下次重试。');
            }
            [$nodes, $cursor] = $this->page($product['variants'] ?? null, $seen);
            foreach ($nodes as $variant) {
                if (! isset($variant['id'], $variant['title'], $variant['inventoryItem']['id']) || ! is_bool($variant['inventoryItem']['tracked'] ?? null)) {
                    throw new ShopifyApiException('变体响应不完整。');
                }
                $levels = [];
                if ($variant['inventoryItem']['tracked']) {
                    $levelCursor = null;
                    $levelSeen = [];
                    do {
                        $inventory = $this->client->executeSyncQuery($connection, self::INVENTORY_QUERY, ['id' => $variant['inventoryItem']['id'], 'after' => $levelCursor]);
                        [$rows, $levelCursor] = $this->page(data_get($inventory, 'data.inventoryItem.inventoryLevels'), $levelSeen);
                        foreach ($rows as $row) {
                            $location = $row['location'] ?? [];
                            $quantity = collect($row['quantities'] ?? [])->firstWhere('name', 'available');
                            if (! isset($location['id'], $location['name']) || ! is_bool($location['isActive'] ?? null) || ! is_int($quantity['quantity'] ?? null)) {
                                throw new ShopifyApiException('库存响应不完整。');
                            }
                            if ($location['isActive']) {
                                $levels[$location['id']] = ['name' => $location['name'], 'available' => $quantity['quantity']];
                            }
                        }
                    } while ($levelCursor !== null);
                }
                ksort($levels);
                $snapshot['variants'][$variant['id']] = ['title' => $variant['title'], 'sku' => $variant['sku'] ?? '',
                    'tracked' => $variant['inventoryItem']['tracked'], 'levels' => $levels];
            }
        } while ($cursor !== null);
        ksort($snapshot['variants']);

        return $snapshot;
    }

    private function page(mixed $page, array &$seen): array
    {
        if (! is_array($page) || ! is_array($page['nodes'] ?? null) || ! is_bool($page['pageInfo']['hasNextPage'] ?? null)) {
            throw new ShopifyApiException('分页响应不完整。');
        }
        $cursor = $page['pageInfo']['hasNextPage'] ? ($page['pageInfo']['endCursor'] ?? null) : null;
        if ($page['pageInfo']['hasNextPage'] && (! is_string($cursor) || $cursor === '' || in_array($cursor, $seen, true) || count($seen) >= 100)) {
            throw new ShopifyApiException('分页未完成，下次重试。');
        }
        $seen[] = $cursor;

        return [$page['nodes'], $cursor];
    }
}
