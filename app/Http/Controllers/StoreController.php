<?php

namespace App\Http\Controllers;

use App\Exceptions\ShopifyOAuthException;
use App\Http\Requests\ConnectShopifyStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use App\Services\Shopify\ShopifyOAuthService;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class StoreController extends Controller
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function index(Request $request): InertiaResponse
    {
        $this->authorize('viewAny', Store::class);
        $organization = $this->currentOrganization->require();
        $user = $request->user();

        $stores = Store::query()
            ->whereBelongsTo($organization)
            ->when(! $user->isSuperAdmin(), fn ($query) => $query
                ->whereHas('members', fn ($query) => $query->whereKey($user->getKey())))
            ->with(['shopifyConnection', 'appInstallations.app'])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Stores/Index', [
            'stores' => StoreResource::collection($stores),
        ]);
    }

    public function create(): InertiaResponse
    {
        $this->authorize('create', Store::class);

        return Inertia::render('Stores/Create', [
            'callbackUrl' => (string) (config('shopify.redirect_uri')
                ?: rtrim((string) config('shopify.app_url'), '/').'/shopify/oauth/callback'),
            'configured' => filled(config('shopify.client_id')) && filled(config('shopify.client_secret')),
        ]);
    }

    public function store(ConnectShopifyStoreRequest $request, ShopifyOAuthService $oauth): Response
    {
        $this->authorize('create', Store::class);
        try {
            $authorization = $oauth->begin(
                $this->currentOrganization->require(),
                $request->user(),
                $request->validated('name'),
                $request->validated('shop_domain'),
            );
        } catch (ShopifyOAuthException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->redirectToShopify($authorization);
    }

    public function show(Store $store): InertiaResponse
    {
        $this->authorize('view', $store);

        return Inertia::render('Stores/Show', [
            'store' => new StoreResource($store->load(['shopifyConnection', 'appInstallations.app'])),
        ]);
    }

    public function connect(ConnectShopifyStoreRequest $request, Store $store, ShopifyOAuthService $oauth): Response
    {
        $this->authorize('connect', $store);
        try {
            $authorization = $oauth->begin(
                $this->currentOrganization->require(),
                $request->user(),
                $request->validated('name'),
                $request->validated('shop_domain'),
                $store,
            );
        } catch (ShopifyOAuthException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return $this->redirectToShopify($authorization);
    }

    /** @param array{authorization_url: string, state: string, state_record: mixed, store: Store} $authorization */
    private function redirectToShopify(array $authorization): Response
    {
        $secure = str_starts_with($authorization['state_record']->redirect_uri, 'https://');
        $cookie = new Cookie(
            ShopifyOAuthService::STATE_COOKIE,
            $authorization['state'],
            now()->addMinutes((int) config('shopify.state_ttl_minutes', 10)),
            '/',
            null,
            $secure,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );

        return Inertia::location($authorization['authorization_url'])->withCookie($cookie);
    }
}
