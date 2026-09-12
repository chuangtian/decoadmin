<?php

namespace DecoReviews\Controllers;

use App\Models\Product;
use App\Models\Store;
use DecoReviews\Models\Media;
use DecoReviews\Services\InvitationService;
use DecoReviews\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StorefrontController
{
    public function __construct(private ReviewService $reviews) {}

    public function feed(Request $request)
    {
        $store = $request->attributes->get('deco_reviews_store');
        abort_unless($store instanceof Store, 401);

        return response()->json($this->data($store, $request))->header('Cache-Control', 'no-store');
    }

    public function data(Store $store, Request $request, bool $preview = false): array
    {
        $this->reviews->active($store);
        $settings = $this->reviews->settings($store);
        abort_unless($preview || $settings['enabled'], 404);
        $values = $request->validate(['product_id' => 'nullable|string|max:80', 'kind' => 'nullable|in:product,store', 'rating' => 'nullable|integer|between:1,5', 'sort' => 'nullable|in:newest,oldest,highest,lowest', 'page' => 'nullable|integer|min:1|max:500']);
        $base = $this->reviews->scoped($store)->where('status', 'published');
        if (isset($values['kind'])) {
            $base->where('kind', $values['kind']);
        }
        if (! empty($values['product_id'])) {
            $external = preg_replace('#^gid://shopify/Product/#', '', $values['product_id']);
            $id = Product::where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->whereIn('shopify_product_id', [$external, 'gid://shopify/Product/'.$external])->value('id');
            $base->where('product_id', $id ?? 0);
        }
        $distribution = (clone $base)->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $count = (int) $distribution->sum();
        $sum = $distribution->reduce(fn ($total, $number, $rating) => $total + $number * $rating, 0);
        $query = clone $base;
        if (isset($values['rating'])) {
            $query->where('rating', $values['rating']);
        }
        $sort = $values['sort'] ?? 'newest';
        if (in_array($sort, ['highest', 'lowest'])) {
            $query->orderBy('rating', $sort === 'highest' ? 'desc' : 'asc');
        }
        $page = $query->with(['media', 'product'])->orderByDesc('featured')->orderBy('reviewed_at', $sort === 'oldest' ? 'asc' : 'desc')->orderByDesc('id')->paginate($settings['page_size']);

        return ['data' => $page->getCollection()->map(fn ($review) => $this->reviews->serialize($review, $store, false, $settings, $preview))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'summary' => ['count' => $count, 'average' => $count ? round($sum / $count, 2) : 0,
                'distribution' => collect(range(1, 5))->mapWithKeys(fn ($rating) => [$rating => (int) ($distribution[$rating] ?? 0)])->all()],
            'settings' => array_intersect_key($settings, array_flip(['star_color', 'layout', 'heading', 'show_verified', 'show_incentive', 'corner_style']))];
    }

    public function media(string $media)
    {
        $asset = Media::where('uuid', $media)->with('review')->firstOrFail();
        $store = Store::where('organization_id', $asset->organization_id)->whereKey($asset->store_id)->firstOrFail();
        $this->reviews->active($store);
        abort_unless($this->reviews->settings($store)['enabled'] && $asset->review
            && $asset->review->status === 'published' && (int) $asset->review->organization_id === (int) $store->organization_id && (int) $asset->review->store_id === (int) $store->id, 404);

        return Storage::disk('local')->response($asset->path, null, ['Content-Type' => $asset->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }

    public function write(Request $request, string $invitation, InvitationService $invites)
    {
        [$store, $invite] = $invites->resolve($invitation);
        abort_unless(in_array($invite->status, ['sent', 'scheduled', 'completed']), 409);

        return response()->view('deco-reviews::storefront', ['feedUrl' => null, 'submitUrl' => $request->fullUrl(), 'invitationProduct' => ['title' => $invite->product?->title]])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function submit(Request $request, string $invitation, InvitationService $invites)
    {
        $request->validate(['consent' => 'accepted']);
        $review = $invites->submit($invitation, $request->all(), $request->file('media', []));

        return response()->json(['data' => ['uuid' => $review->uuid, 'status' => $review->status], 'message' => 'Thank you. Your review has been received.'], 201)->header('Cache-Control', 'no-store');
    }

    public function unsubscribe(Request $request, string $invitation, InvitationService $invites)
    {
        $invites->resolve($invitation);
        if ($request->isMethod('post')) {
            $invites->unsubscribe($invitation);

            return response('You have unsubscribed from review invitations.', 200)->header('Content-Type', 'text/plain');
        }

        return response()->view('deco-reviews::unsubscribe', ['action' => $request->fullUrl()])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function asset(string $asset)
    {
        abort_unless(in_array($asset, ['storefront.js', 'storefront.css']), 404);

        return response()->file(__DIR__.'/../../frontend/'.$asset, ['Content-Type' => str_ends_with($asset, '.js') ? 'text/javascript' : 'text/css', 'Cache-Control' => 'public,max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
