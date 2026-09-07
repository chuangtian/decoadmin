<?php

namespace CommunityReviews\Controllers;

use App\Models\ModelAssetFolder;
use App\Models\ModelAssetImage;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ReputationMention;
use App\Models\Store;
use CommunityReviews\Services\ReviewFeed;
use CommunityReviews\Services\ReviewManager;
use CommunityReviews\Services\ShopifyClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ManagementController
{
    public function __construct(private ReviewManager $manager, private ReviewFeed $feed, private ShopifyClient $shopify) {}

    private function authorize(Request $request, Organization $organization, Store $store, bool $write = false): void
    {
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        $this->manager->authorize($request->user(), $store, $write);
    }

    public function index(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store);
        $request->session()->put('current_store_id', $store->id);
        $links = $this->feed->links($store)->keyBy('folder_id');
        $folders = ModelAssetFolder::query()->where('organization_id', $organization->id)->where('store_id', $store->id)
            ->withCount('images')->orderBy('name')->limit(100)->get();

        return Inertia::render('CommunityReviews/Index', [
            'organization' => $organization->only(['id', 'name']), 'store' => $store->only(['id', 'name', 'shopify_domain']),
            'settings' => $this->feed->settings($store)->only(['enabled', 'heading', 'read_more_url', 'card_count']),
            'connected' => $this->shopify->installation($store) !== null,
            'canManage' => $request->user()->hasPermission('products.update', $organization, $store),
            'reviewCount' => ReputationMention::query()->where('organization_id', $organization->id)->where('store_id', $store->id)
                ->where('is_active', true)->whereIn('rating', [4, 5])->whereNotNull('reviewer_name')->whereNotNull('content')->count(),
            'folders' => $folders->map(function ($folder) use ($links) {
                $link = $links->get($folder->id);

                return ['id' => $folder->uuid, 'name' => $folder->name, 'image_count' => $folder->images_count,
                    'product_id' => $link?->product_id, 'product_title' => $link?->product?->title,
                    'product_status' => $link?->product?->status, 'label' => $link?->label ?? $folder->name,
                    'series' => $link?->series ?? '', 'aliases' => $link?->aliases ?? [], 'enabled' => $link?->enabled ?? true];
            })->values(),
        ]);
    }

    public function save(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store, true);
        $values = $request->validate([
            'enabled' => ['required', 'boolean'], 'heading' => ['required', 'string', 'max:200'],
            'read_more_url' => ['nullable', 'url:https', 'max:2048', function ($attribute, $value, $fail) {
                if (parse_url($value, PHP_URL_USER) !== null || parse_url($value, PHP_URL_PASS) !== null) {
                    $fail('链接不能包含登录凭据。');
                }
            }],
            'card_count' => ['required', 'integer', 'min:2', 'max:24'],
            'models' => ['present', 'array', 'max:100'], 'models.*.folder_id' => ['required', 'uuid', 'distinct'],
            'models.*.product_id' => ['required', 'integer'], 'models.*.label' => ['required', 'string', 'max:120'],
            'models.*.series' => ['nullable', 'string', 'max:120'], 'models.*.enabled' => ['required', 'boolean'],
            'models.*.aliases' => ['present', 'array', 'max:20'], 'models.*.aliases.*' => ['string', 'max:120'],
        ]);
        $this->manager->save($store, $request->user(), $values);

        return back()->with('success', '买家秀设置已保存。');
    }

    public function products(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store);
        $values = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $products = Product::query()->forOrganization($organization)->forStore($store)
            ->when($values['q'] ?? '', fn ($query, $term) => $query->where('title', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderBy('title')->limit(30)->get(['id', 'title', 'status', 'featured_image_url']);

        return response()->json(['data' => $products]);
    }

    public function preview(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store);

        return response()->json(['data' => $this->feed->build($store, true)])->header('Cache-Control', 'no-store');
    }

    public function image(Request $request, Organization $organization, Store $store, string $image)
    {
        $this->authorize($request, $organization, $store);
        $asset = ModelAssetImage::query()->where('organization_id', $organization->id)->where('store_id', $store->id)->where('uuid', $image)->firstOrFail();
        abort_unless($asset->thumbnail_path && Storage::disk($asset->disk)->exists($asset->thumbnail_path), 404);

        return Storage::disk($asset->disk)->response($asset->thumbnail_path, null, ['Content-Type' => 'image/webp', 'Cache-Control' => 'private, max-age=300']);
    }
}
