<?php

namespace App\Services\InstagramFeed;

use App\Models\Store;

/**
 * 关联商品的读取层。
 *
 * 媒体上只存商品 GID，标题、handle、主图这些展示信息一律实时查 Admin API，
 * 不做本地冗余 —— 商家改了商品名或换了主图，后台和前台都应该立刻跟上。
 *
 * 已删除的商品在 nodes 里返回 null，直接跳过，上层据此把失效关联过滤掉，
 * 不会把死链发到前台。
 */
class InstagramProductResolver
{
    /** nodes 查询本身有上限，这里也顺便挡住异常数据。 */
    private const MAX_IDS = 100;

    private const PRODUCTS_QUERY = <<<'GRAPHQL'
        query InstagramFeedLinkedProducts($ids: [ID!]!) {
          nodes(ids: $ids) {
            id
            ... on Product {
              title
              handle
              featuredImage { url altText }
            }
          }
        }
        GRAPHQL;

    public function __construct(private InstagramFeedShopifyClient $client) {}

    /**
     * @param  list<string>  $productGids
     * @return array<string, array{id: string, title: string, handle: string, image_url: string|null, image_alt: string|null}>
     */
    public function resolve(Store $store, array $productGids): array
    {
        $ids = array_slice(array_values(array_unique(array_filter(
            $productGids,
            fn (mixed $gid): bool => is_string($gid) && $gid !== '',
        ))), 0, self::MAX_IDS);
        if ($ids === []) {
            return [];
        }

        $payload = $this->client->graphql($store, self::PRODUCTS_QUERY, ['ids' => $ids]);
        $nodes = data_get($payload, 'data.nodes', []);
        $resolved = [];

        foreach (is_array($nodes) ? $nodes : [] as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = $node['id'] ?? null;
            $handle = $node['handle'] ?? null;
            if (! is_string($id) || $id === '' || ! is_string($handle) || $handle === '') {
                continue;
            }

            $resolved[$id] = [
                'id' => $id,
                'title' => is_string($node['title'] ?? null) && $node['title'] !== '' ? $node['title'] : $handle,
                'handle' => $handle,
                'image_url' => is_string(data_get($node, 'featuredImage.url')) ? data_get($node, 'featuredImage.url') : null,
                'image_alt' => is_string(data_get($node, 'featuredImage.altText')) ? data_get($node, 'featuredImage.altText') : null,
            ];
        }

        return $resolved;
    }
}
