<?php

namespace CommunityReviews\Services;

use App\Models\ModelAssetFolder;
use App\Models\ModelAssetImage;
use App\Models\ReputationMention;
use App\Models\Store;
use CommunityReviews\Models\ModelLink;
use CommunityReviews\Models\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReviewFeed
{
    public function __construct(private ShopifyClient $shopify) {}

    public function settings(Store $store): Settings
    {
        return Settings::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->first()
            ?? new Settings(['organization_id' => $store->organization_id, 'store_id' => $store->id,
                'enabled' => false, 'heading' => 'Hear from our community', 'read_more_url' => null, 'card_count' => 12]);
    }

    public function links(Store $store): Collection
    {
        return ModelLink::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->with(['folder', 'product'])->orderBy('id')->limit(100)->get()
            ->filter(fn ($link) => $link->folder && $link->product
                && (int) $link->folder->store_id === (int) $store->id
                && (int) $link->folder->organization_id === (int) $store->organization_id
                && (int) $link->product->store_id === (int) $store->id
                && (int) $link->product->organization_id === (int) $store->organization_id);
    }

    public function eligible(Store $store, Collection $links): Collection
    {
        $candidates = $links->filter(fn ($link) => $link->enabled && $link->product->status === 'active');
        $products = $this->shopify->availableProducts($store, $candidates->map(fn ($link) => 'gid://shopify/Product/'.$link->product->shopify_product_id)->all());

        return $candidates->filter(function ($link) use ($products) {
            $product = $products['gid://shopify/Product/'.$link->product->shopify_product_id] ?? null;
            if (! $product) {
                return false;
            }
            $link->setAttribute('live_product', $product);

            return true;
        });
    }

    public function build(Store $store, bool $preview = false, string $proxyPath = '/apps/community-reviews', ?string $visitor = null): array
    {
        $cache = app(FeedCache::class);
        $pool = $cache->remember($store, 'pool', 300, fn () => $this->pool($store));
        $settings = $pool['settings'];
        $empty = ['version' => 1, 'heading' => $settings['heading'], 'read_more_url' => $settings['read_more_url'], 'cards' => []];
        if (! $preview && ! $settings['enabled']) return $empty;
        $products = $this->shopify->availableProducts($store, array_column($pool['models'], 'product_id'));
        $models = array_filter($pool['models'], fn ($model) => isset($products[$model['product_id']]));
        if ($models === []) return $empty;

        return $cache->select($store, $preview ? null : $visitor, function ($seenReviews, $seenImages) use ($pool, $models, $products, $settings, $empty, $store, $preview, $proxyPath) {
            $reviewOrder = array_flip($seenReviews);
            $imageOrder = array_flip($seenImages);
            $reviews = collect($pool['reviews'])->shuffle()->sortBy(fn ($review) => [
                isset($reviewOrder[$review['key']]) ? $reviewOrder[$review['key']] + 1 : 0,
                $review['folders'] === [] ? 1 : 0,
            ]);
            $images = collect($pool['images'])->filter(fn ($image) => isset($models[$image['folder_id']]))
                ->shuffle()->sortBy(fn ($image) => isset($imageOrder[$image['key']]) ? $imageOrder[$image['key']] + 1 : 0);
            $usedImages = []; $usedReviews = []; $cards = [];
            // Exhaust unused review/image pairs across all models before recycling a scarce model's images.
            foreach ([[false, false], [true, false], [false, true], [true, true]] as [$allowSeenReviews, $allowSeenImages]) {
                foreach ($reviews as $review) {
                    if (isset($usedReviews[$review['key']]) || (! $allowSeenReviews && isset($reviewOrder[$review['key']]))) continue;
                    $image = $images->first(fn ($candidate) => ! isset($usedImages[$candidate['key']])
                        && ($allowSeenImages || ! isset($imageOrder[$candidate['key']]))
                        && ($review['folders'] === [] || in_array($candidate['folder_id'], $review['folders'], true)));
                    if (! $image) continue;
                    $model = $models[$image['folder_id']];
                    $product = $products[$model['product_id']];
                    $usedImages[$image['key']] = true;
                    $usedReviews[$review['key']] = true;
                    $cards[] = [
                        'id' => $review['id'], 'name' => $review['name'], 'rating' => $review['rating'], 'content' => $review['content'],
                        'image' => ['id' => $image['uuid'], 'url' => $preview
                            ? route('community-reviews.preview-image', [$store->organization_id, $store->id, $image['uuid']])
                            : $proxyPath.'/images/'.$image['uuid'], 'alt' => $model['label'], 'width' => $image['width'], 'height' => $image['height']],
                        'product' => ['id' => $product['id'], 'label' => $model['label'], 'series' => $model['series'],
                            'handle' => $product['handle'], 'url' => ($preview ? 'https://'.$store->shopify_domain : '').'/products/'.rawurlencode($product['handle']),
                            'image_url' => data_get($product, 'featuredImage.url'), 'image_alt' => $model['label']],
                    ];
                    if (count($cards) >= min(24, max(1, $settings['card_count']))) break 2;
                }
            }
            shuffle($cards);
            return ['feed' => [...$empty, 'cards' => $cards], 'reviews' => array_keys($usedReviews), 'images' => array_keys($usedImages)];
        });
    }

    private function pool(Store $store): array
    {
        $settings = $this->settings($store)->only(['enabled', 'heading', 'read_more_url', 'card_count']);
        $links = $this->links($store);
        $models = [];
        foreach ($links as $link) {
            if (! $link->enabled || $link->product->status !== 'active') continue;
            $models[$link->folder_id] = ['product_id' => 'gid://shopify/Product/'.$link->product->shopify_product_id,
                'label' => $link->label, 'series' => $link->series];
        }
        $images = [];
        // Bounded, rotating candidate pools keep large libraries off the visitor response.
        foreach (ModelAssetImage::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereIn('folder_id', array_keys($models))->inRandomOrder()->limit(5000)->get() as $image) {
            if (! $image->thumbnail_path || ! Storage::disk($image->disk)->exists($image->thumbnail_path)) continue;
            $digestKey = app(FeedCache::class)->prefix($store->organization_id, $store->id).':digest:'.$image->uuid.':'.md5($image->thumbnail_path.'|'.$image->updated_at);
            $digest = \Illuminate\Support\Facades\Cache::remember($digestKey, 604800,
                fn () => hash('sha256', Storage::disk($image->disk)->get($image->thumbnail_path)));
            $images[] = ['uuid' => $image->uuid, 'key' => $digest, 'folder_id' => (int) $image->folder_id, 'width' => $image->width, 'height' => $image->height];
        }
        $aliases = [];
        foreach (ModelAssetFolder::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->orderBy('id')->limit(500)->get(['id', 'name']) as $folder) {
            $aliases[] = ['folder_id' => $folder->id, 'text' => $folder->name];
        }
        foreach ($links as $link) {
            foreach ([$link->label, ...($link->aliases ?? [])] as $alias) $aliases[] = ['folder_id' => $link->folder_id, 'text' => $alias];
        }
        $reviews = [];
        foreach (ReputationMention::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('is_active', true)->whereIn('rating', [4, 5])->whereNotNull('reviewer_name')->whereNotNull('content')
            ->select(['id', 'uuid', 'organization_id', 'store_id', 'reviewer_name', 'rating', 'content', 'title', 'model_name'])
            ->with(['productMatches' => fn ($query) => $query->where('organization_id', $store->organization_id)->where('store_id', $store->id)])
            ->inRandomOrder()->limit(1000)->get() as $review) {
            $name = $this->publicText(trim((string) $review->reviewer_name), 100);
            $content = $this->publicText(trim((string) $review->content), 2400);
            if ($name === '' || $content === '') continue;
            $folders = $this->matchingFolders($review, $aliases, $links);
            if ($folders === null) continue;
            $reviews[] = ['id' => $review->uuid, 'name' => $name, 'content' => $content, 'rating' => (int) $review->rating,
                'folders' => $folders, 'key' => hash('sha256', mb_strtolower(preg_replace('/\s+/u', ' ', $content)))];
        }
        return compact('settings', 'models', 'images', 'reviews');
    }

    public function matchingFolders(ReputationMention $review, array $aliases, Collection $links): ?array
    {
        $explicit = trim((string) $review->model_name);
        $text = $explicit !== '' ? $explicit : ($review->title.' '.$review->content);
        $matches = [];
        foreach ($aliases as $alias) {
            $tokens = preg_split('/[\s\p{P}]+/u', trim($alias['text']), -1, PREG_SPLIT_NO_EMPTY);
            if (! $tokens) {
                continue;
            }
            $pattern = '/(?<![\p{L}\p{N}])'.implode('[\s\p{P}]*', array_map(fn ($token) => preg_quote($token, '/'), $tokens)).'(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $text, $found, PREG_OFFSET_CAPTURE)) {
                $matches[] = ['folder_id' => $alias['folder_id'], 'length' => mb_strlen($alias['text']), 'offset' => $found[0][1]];
            }
        }
        if ($matches !== []) {
            usort($matches, fn ($a, $b) => $b['length'] <=> $a['length'] ?: $a['offset'] <=> $b['offset']);

            return [$matches[0]['folder_id']];
        }
        if ($explicit !== '') {
            return null;
        }
        $stored = $review->productMatches;
        if ($stored->isNotEmpty()) {
            $primary = $stored->where('match_role', 'primary');
            $ids = ($primary->isEmpty() ? $stored : $primary)->pluck('product_id');
            $folders = $links->whereIn('product_id', $ids)->pluck('folder_id')->all();

            return $folders ?: null;
        }

        return [];
    }

    private function publicText(string $text, int $length): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu', '[email removed]', $text);
        $text = preg_replace('/(?<!\w)\+?\d[\d ()-]{8,}\d(?!\w)/u', '[number removed]', $text);

        return Str::limit($text, $length, '');
    }
}
