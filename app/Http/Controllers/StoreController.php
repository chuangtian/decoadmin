<?php

namespace App\Http\Controllers;

use App\Exceptions\ShopifyOAuthException;
use App\Http\Requests\ConnectShopifyStoreRequest;
use App\Http\Resources\StoreResource;
use App\Models\AuditLog;
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
            ->with(['shopifyConnection', 'latestSyncJob'])
            ->withCount(['appInstallations as installed_apps_count' => fn ($query) => $query->where('status', 'active')])
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

        $this->saveEnvironment($authorization['store'], $request->validated('environment'));

        return $this->redirectToShopify($authorization);
    }

    public function show(Store $store): InertiaResponse
    {
        $this->authorize('view', $store);

        $connectionHistory = AuditLog::query()
            ->where('store_id', $store->getKey())
            ->whereIn('action', [
                'shopify_connection_connected',
                'shopify_connection_warning',
                'shopify_connection_invalid',
                'shopify_connection_disconnected',
                'shopify_connection_reconnected',
            ])
            ->with('user:id,name')
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (AuditLog $audit): array => [
                'id' => $audit->id,
                'action' => $audit->action,
                'actor' => $audit->user?->name ?? 'System',
                'previous_status' => data_get($audit->metadata, 'previous_status')
                    ?? data_get($audit->old_values, 'status'),
                'new_status' => data_get($audit->metadata, 'new_status')
                    ?? data_get($audit->new_values, 'status'),
                'reason' => data_get($audit->metadata, 'reason'),
                'created_at' => $audit->created_at?->toIso8601String(),
            ])
            ->values();

        return Inertia::render('Stores/Show', [
            'store' => new StoreResource($store
                ->load(['shopifyConnection', 'appInstallations.app', 'latestSyncJob'])
                ->loadCount(['appInstallations as installed_apps_count' => fn ($query) => $query->where('status', 'active')])),
            'connectionHistory' => $connectionHistory,
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

        $this->saveEnvironment($authorization['store'], $request->validated('environment'));

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

    private function saveEnvironment(Store $store, string $environment): void
    {
        $store->forceFill([
            'settings' => [
                ...($store->settings ?? []),
                'environment' => $environment,
            ],
        ])->save();
    }
}
