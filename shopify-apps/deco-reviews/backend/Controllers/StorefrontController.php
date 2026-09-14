<?php

namespace DecoReviews\Controllers;

use App\Models\Product;
use App\Models\Store;
use DecoReviews\Models\Media;
use DecoReviews\Services\FormService;
use DecoReviews\Services\InvitationService;
use DecoReviews\Services\ProductGroupService;
use DecoReviews\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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
        $values = $request->validate(['product_id' => 'nullable|string|max:80', 'kind' => 'nullable|in:all,product,store', 'rating' => 'nullable|integer|between:1,5', 'sort' => 'nullable|in:newest,oldest,highest,lowest', 'page' => 'nullable|integer|min:1|max:500']);
        $base = $this->reviews->scoped($store)->where('status', 'published');
        $productId = null;
        if (isset($values['kind']) && $values['kind'] !== 'all') {
            $base->where('kind', $values['kind']);
        }
        if (! empty($values['product_id'])) {
            $productId = $this->productId($store, $values['product_id']);
            $base->whereIn('product_id', $productId ? app(ProductGroupService::class)->sharedProductIds($store, $productId) : [0]);
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

        $form = $settings['organic_collection_enabled'] && $productId
            ? $this->publicForm(app(FormService::class)->forProduct($store, 'product', $productId)) : null;

        return ['data' => $page->getCollection()->map(fn ($review) => $this->reviews->serialize($review, $store, false, $settings, $preview))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'summary' => ['count' => $count, 'average' => $count ? round($sum / $count, 2) : 0,
                'distribution' => collect(range(1, 5))->mapWithKeys(fn ($rating) => [$rating => (int) ($distribution[$rating] ?? 0)])->all()],
            'settings' => array_intersect_key($settings, array_flip(['star_color', 'layout', 'heading', 'show_verified', 'show_incentive', 'corner_style', 'organic_collection_enabled'])),
            'form' => $form];
    }

    public function organic(Request $request)
    {
        return $this->organicSubmission($request, false);
    }

    public function storeReview(Request $request)
    {
        $store = $request->attributes->get('deco_reviews_store');
        abort_unless($store instanceof Store, 401);
        $this->reviews->active($store);
        $settings = $this->reviews->settings($store);
        abort_unless($settings['enabled'] && $settings['store_review_collection_enabled'], 404);

        return response()->view('deco-reviews::storefront', [
            'feedUrl' => null,
            'submitUrl' => config('deco_reviews.active.proxy_path').'/store-reviews',
            'formConfig' => app(FormService::class)->forProduct($store, 'store', null),
            'collectEmail' => true,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function happyCustomers(Request $request)
    {
        $store = $request->attributes->get('deco_reviews_store');
        abort_unless($store instanceof Store, 401);
        $this->reviews->active($store);
        $settings = $this->reviews->settings($store);
        abort_unless($settings['enabled'] && $settings['happy_customers_page_enabled'], 404);

        return response()->view('deco-reviews::storefront', [
            'feedUrl' => config('deco_reviews.active.proxy_path').'/feed',
            'feedKind' => 'all',
            'submitUrl' => null,
            'pageTitle' => $settings['heading'],
            'pageDescription' => 'Verified and customer-submitted reviews published by this store.',
        ])->header('Cache-Control', 'no-store')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function organicStore(Request $request)
    {
        return $this->organicSubmission($request, true);
    }

    private function organicSubmission(Request $request, bool $storeReview)
    {
        $store = $request->attributes->get('deco_reviews_store');
        abort_unless($store instanceof Store, 401);
        $this->reviews->active($store);
        $settings = $this->reviews->settings($store);
        $enabled = $storeReview ? $settings['store_review_collection_enabled'] : $settings['organic_collection_enabled'];
        abort_unless($settings['enabled'] && $enabled, 404);

        $values = $request->validate([
            'product_id' => $storeReview ? 'prohibited' : 'required|string|max:80', 'author_name' => 'required|string|max:120',
            'author_email' => 'required|email:rfc|max:254', 'rating' => 'required|integer|between:1,5',
            'title' => 'nullable|string|max:200', 'body' => 'required|string|max:10000',
            'form_version' => 'required|string|max:36', 'answers' => 'nullable|array|max:10',
            'consent' => 'accepted', 'website' => 'prohibited',
        ]);
        $productId = $storeReview ? null : $this->productId($store, $values['product_id']);
        if (! $storeReview && ! $productId) {
            throw ValidationException::withMessages(['product_id' => 'This product is not available for reviews.']);
        }
        $kind = $storeReview ? 'store' : 'product';
        $rateKey = 'deco-reviews:organic:'.hash('sha256', $store->organization_id.':'.$store->id.':'.$kind.':'.($productId ?? 0).':'.$this->reviews->emailHash($store, $values['author_email']));
        abort_unless(RateLimiter::attempt($rateKey, 3, fn () => true, 3600), 429, 'Too many review attempts. Please try again later.');
        $files = $request->file('media', []);
        $files = is_array($files) ? $files : [$files];
        $review = $this->reviews->create($store, array_merge($values, ['kind' => $kind, 'product_id' => $productId]), $files, null,
            ['source' => 'organic', 'verified_source' => 'none']);

        return response()->json(['data' => ['received' => true],
            'message' => app(FormService::class)->configuration($store)['thank_you']], 201)->header('Cache-Control', 'no-store');
    }

    public function media(string $media)
    {
        $asset = Media::where('uuid', $media)->with('review')->firstOrFail();
        $store = Store::where('organization_id', $asset->organization_id)->whereKey($asset->store_id)->firstOrFail();
        $this->reviews->active($store);
        abort_unless($this->reviews->settings($store)['enabled'] && $asset->review
            && $asset->review->status === 'published' && (int) $asset->review->organization_id === (int) $store->organization_id && (int) $asset->review->store_id === (int) $store->id, 404);

        abort_unless(Storage::disk('local')->exists($asset->path), 404);

        // Binary responses support browser byte ranges for video seeking.
        return response()->file(Storage::disk('local')->path($asset->path), ['Content-Type' => $asset->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }

    public function write(Request $request, string $invitation, InvitationService $invites)
    {
        [$store, $invite] = $invites->resolve($invitation);
        abort_unless(in_array($invite->status, ['sent', 'scheduled', 'completed']), 409);

        return response()->view('deco-reviews::storefront', ['feedUrl' => null, 'submitUrl' => $request->fullUrl(),
            'formCompleted' => $invite->status === 'completed', 'invitationProduct' => ['title' => $invite->product?->title],
            'formConfig' => app(FormService::class)->forProduct($store, 'product', $invite->product_id)])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function submit(Request $request, string $invitation, InvitationService $invites)
    {
        $request->validate(['consent' => 'accepted']);
        $review = $invites->submit($invitation, $request->all(), $request->file('media', []));

        [$store] = $invites->resolve($invitation);

        return response()->json(['data' => ['uuid' => $review->uuid, 'status' => $review->status],
            'message' => app(FormService::class)->configuration($store)['thank_you']], 201)->header('Cache-Control', 'no-store');
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

    private function productId(Store $store, string $externalId): ?int
    {
        $external = preg_replace('#^gid://shopify/Product/#', '', $externalId);

        return Product::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereIn('shopify_product_id', [$external, 'gid://shopify/Product/'.$external])->value('id');
    }

    private function publicForm(array $form): array
    {
        return array_intersect_key($form, array_flip(['version', 'heading', 'description', 'name_label', 'title_label', 'body_label', 'submit_label', 'thank_you', 'allow_photos', 'allow_video'])) + [
            'questions' => collect($form['questions'] ?? [])->map(fn ($question) => array_intersect_key($question,
                array_flip(['id', 'label', 'type', 'required', 'public', 'options', 'min', 'max'])))->values()->all(),
        ];
    }
}
