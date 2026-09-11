<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\AuditLog;
use App\Models\InstagramGallery;
use App\Models\InstagramMedia;
use App\Models\Store;
use App\Models\User;

/**
 * 前台数据交付：把「要展示的内容」写进 app-data metafield（挂在 AppInstallation 上，
 * 商家后台看不到），Theme App Extension 用 Liquid 的 app.metafields 直接读，服务端渲染。
 *
 * 这样前台不依赖 DecoAdmin 在线：后端挂了、隧道断了，店铺前台照样正常展示。
 * https://shopify.dev/docs/apps/build/custom-data/ownership
 */
class InstagramFeedPublisher
{
    private const METAFIELDS_SET_MUTATION = <<<'GRAPHQL'
        mutation InstagramFeedPublish($metafields: [MetafieldsSetInput!]!) {
          metafieldsSet(metafields: $metafields) {
            metafields { id namespace key updatedAt }
            userErrors { field message code }
          }
        }
        GRAPHQL;

    public function __construct(
        private InstagramFeedShopifyClient $client,
        private InstagramProductResolver $products,
    ) {}

    /**
     * 组装并发布，前台立即生效。
     *
     * @return array{galleries: int, items: int, published_at: string}
     */
    public function publish(Store $store, ?User $actor = null): array
    {
        $installation = $this->client->installationFor($store);
        $payload = $this->buildPayload($store);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded)) {
            throw new InstagramFeedException('FEED_ENCODE_FAILED', '前台数据序列化失败。', 500);
        }
        // Shopify 单个 metafield 值上限 64KB。
        if (strlen($encoded) > 64000) {
            throw new InstagramFeedException(
                'FEED_PAYLOAD_TOO_LARGE',
                '前台数据超过 Shopify metafield 上限，请减少展示组内容或缩短文案。',
                422,
            );
        }

        $namespace = (string) config('instagram_feed.metafield.namespace');
        $key = (string) config('instagram_feed.metafield.key');
        $response = $this->client->graphql($store, self::METAFIELDS_SET_MUTATION, [
            'metafields' => [[
                'ownerId' => $installation->app_installation_id,
                'namespace' => $namespace,
                'key' => $key,
                'type' => 'json',
                'value' => $encoded,
            ]],
        ]);

        $userErrors = data_get($response, 'data.metafieldsSet.userErrors', []);
        $savedKey = data_get($response, 'data.metafieldsSet.metafields.0.key');
        if ((is_array($userErrors) && $userErrors !== []) || ! is_string($savedKey) || ! hash_equals($key, $savedKey)) {
            throw new InstagramFeedException('FEED_PUBLISH_FAILED', 'Shopify 未能保存前台展示数据。', 502);
        }

        $publishedAt = now();
        $installation->forceFill(['last_published_at' => $publishedAt])->save();
        $store->instagramAccount?->forceFill(['last_published_at' => $publishedAt])->save();

        $itemCount = array_sum(array_map(
            fn (array $gallery): int => count($gallery['items']),
            $payload['galleries'],
        ));

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor?->getKey(),
            'action' => 'instagram_feed_published',
            'subject_type' => Store::class,
            'subject_id' => $store->id,
            'metadata' => [
                'scope' => 'store',
                'environment' => (string) config('instagram_feed.environment'),
                'galleries' => count($payload['galleries']),
                'items' => $itemCount,
                'bytes' => strlen($encoded),
            ],
        ]);

        return [
            'galleries' => count($payload['galleries']),
            'items' => $itemCount,
            'published_at' => $publishedAt->toIso8601String(),
        ];
    }

    /**
     * 组装要发布到前台的数据。
     *
     * 内容按展示组组织，每组只包含已转存成功（ready）的媒体 —— 没转存完的还挂在
     * Instagram CDN 上，链接会过期，发出去前台迟早挂。
     *
     * @return array{updated_at: string, username: string|null, profile_url: string|null, avatar_url: string|null, galleries: list<array{handle: string, name: string, items: list<array<string, mixed>>}>, items: list<array<string, mixed>>}
     */
    public function buildPayload(Store $store): array
    {
        $account = $store->instagramAccount;
        $galleries = InstagramGallery::query()
            ->where('store_id', $store->id)
            ->with(['items.media'])
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        $productGids = $galleries
            ->flatMap(fn (InstagramGallery $gallery) => $gallery->items->map(fn ($item) => $item->media))
            ->filter()
            ->flatMap(fn (InstagramMedia $media): array => $media->productGids())
            ->unique()
            ->values()
            ->all();
        // 没有关联商品时不必打扰 Shopify。
        $productMap = $productGids === [] ? [] : $this->products->resolve($store, $productGids);

        $maxItems = (int) config('instagram_feed.metafield.max_items_per_gallery', 50);
        $feedGalleries = $galleries->map(fn (InstagramGallery $gallery): array => [
            'handle' => $gallery->handle,
            'name' => $gallery->name,
            'items' => $gallery->items
                ->map(fn ($item) => $item->media)
                ->filter(fn (?InstagramMedia $media): bool => $media instanceof InstagramMedia && $media->isMirrorReady())
                ->take($maxItems)
                ->map(fn (InstagramMedia $media): array => $this->feedItem($media, $productMap))
                ->values()
                ->all(),
        ])->values()->all();

        return [
            'updated_at' => now()->toIso8601String(),
            'username' => $account?->username,
            'profile_url' => $account?->profileUrl(),
            'avatar_url' => $account?->profile_picture_url,
            'galleries' => $feedGalleries,
            // 向下兼容：现有主题区块读的是 feed.items，先指向第一个组。
            'items' => $feedGalleries[0]['items'] ?? [],
        ];
    }

    /**
     * @param  array<string, array{id: string, title: string, handle: string, image_url: string|null, image_alt: string|null}>  $productMap
     * @return array<string, mixed>
     */
    private function feedItem(InstagramMedia $media, array $productMap): array
    {
        $products = [];
        foreach ($media->productGids() as $gid) {
            if (isset($productMap[$gid])) {
                $products[] = ['handle' => $productMap[$gid]['handle'], 'title' => $productMap[$gid]['title']];
            }
        }

        return [
            'id' => $media->ig_media_id,
            'media_type' => $media->media_type,
            'permalink' => $media->permalink,
            // 点击封面后弹窗里嵌的就是这个地址：Instagram 官方 embed，不需要令牌，
            // 视频与轮播都由 Instagram 自己播，绕开它对部分 Reels 不给视频文件的限制。
            'embed_url' => $media->embedUrl(),
            'caption' => $this->sanitizeCaption($media->caption),
            // R2 是纯对象存储，没有 Shopify CDN 那种 ?width= 变体，直接给原图地址。
            // 只有封面图：视频文件不再转存。
            'poster_url' => $media->poster_url,
            'width' => $media->width,
            'height' => $media->height,
            'posted_at' => $media->posted_at?->toIso8601String(),
            'products' => $products,
        ];
    }



    /**
     * 文案会被内联进前台的 JSON script 标签，去掉尖括号避免出现 `</script>` 把标签截断。
     */
    private function sanitizeCaption(?string $caption): ?string
    {
        if (! is_string($caption) || trim($caption) === '') {
            return null;
        }
        $cleaned = trim(mb_substr(
            str_replace(['<', '>'], '', $caption),
            0,
            (int) config('instagram_feed.metafield.max_caption_length', 300),
        ));

        return $cleaned === '' ? null : $cleaned;
    }
}
