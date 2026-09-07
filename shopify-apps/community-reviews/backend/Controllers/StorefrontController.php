<?php

namespace CommunityReviews\Controllers;

use App\Models\ModelAssetImage;
use CommunityReviews\Services\ReviewFeed;
use CommunityReviews\Services\ShopifyClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StorefrontController
{
    public function __construct(private ReviewFeed $feed, private ShopifyClient $shopify) {}

    public function feed(Request $request)
    {
        $values = $request->validate(['visitor' => ['nullable', 'uuid']]);
        $store = $request->attributes->get('community_reviews_store');
        abort_unless($this->shopify->installation($store), 404);
        $path = (string) $request->query('path_prefix', '/apps/community-reviews');
        if (! preg_match('#^/(apps|a|community|tools)/[a-z0-9-]+$#', $path)) {
            $path = '/apps/community-reviews';
        }

        return response()->json(['data' => $this->feed->build($store, false, $path, $values['visitor'] ?? null)])
            ->header('Cache-Control', 'no-store');
    }

    public function image(Request $request, string $image)
    {
        $store = $request->attributes->get('community_reviews_store');
        abort_unless($this->feed->settings($store)->enabled && $this->shopify->installation($store), 404);
        $asset = ModelAssetImage::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('uuid', $image)->firstOrFail();
        $link = $this->feed->links($store)->first(fn ($link) => $link->folder_id === $asset->folder_id && $link->enabled && $link->product->status === 'active');
        abort_unless($link && $asset->thumbnail_path && Storage::disk($asset->disk)->exists($asset->thumbnail_path), 404);
        abort_unless($this->feed->eligible($store, $this->feed->links($store))->contains('folder_id', $asset->folder_id), 404);

        return Storage::disk($asset->disk)->response($asset->thumbnail_path, null, [
            'Content-Type' => 'image/webp', 'Cache-Control' => 'public, max-age=86400, immutable', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
