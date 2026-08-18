<?php

namespace App\Http\Controllers;

use App\Http\Resources\AppInstallationResource;
use App\Http\Resources\AppResource;
use App\Models\App as ShopifyApp;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppController extends Controller
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ShopifyApp::class);
        $organization = $this->currentOrganization->require();
        $storeIds = $this->accessibleStoreIds($request->user(), $organization);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $registry = ShopifyApp::query()->where(function ($query) use ($organization, $storeIds): void {
            $query->whereBelongsTo($organization)
                ->orWhere(function ($query) use ($organization, $storeIds): void {
                    $query->whereNull('organization_id')
                        ->whereHas('installations', fn ($query) => $query
                            ->whereIn('store_id', $storeIds)
                            ->whereHas('store', fn ($query) => $query->whereBelongsTo($organization)));
                });
        });
        $statusOptions = (clone $registry)
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->values();

        $apps = (clone $registry)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('handle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->withCount(['installations as installations_count' => fn ($query) => $query
                ->whereIn('store_id', $storeIds)])
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Apps/Index', [
            'apps' => AppResource::collection($apps),
            'filters' => ['search' => $search, 'status' => $status],
            'statusOptions' => $statusOptions,
        ]);
    }

    public function show(Request $request, ShopifyApp $app): Response
    {
        $this->authorize('view', $app);
        $organization = $this->currentOrganization->require();
        $storeIds = $this->accessibleStoreIds($request->user(), $organization);

        $app->loadCount(['installations as installations_count' => fn ($query) => $query
            ->whereIn('store_id', $storeIds)]);
        $installations = $app->installations()
            ->whereIn('store_id', $storeIds)
            ->whereHas('store', fn ($query) => $query->whereBelongsTo($organization))
            ->with('store:id,organization_id,name,shopify_domain,status')
            ->latest('installed_at')
            ->get();

        return Inertia::render('Apps/Show', [
            'app' => new AppResource($app),
            'installations' => AppInstallationResource::collection($installations),
        ]);
    }

    /** @return list<int> */
    private function accessibleStoreIds(User $user, Organization $organization): array
    {
        return $user->isSuperAdmin()
            ? $organization->stores()->pluck('id')->all()
            : $user->stores()
                ->where('stores.organization_id', $organization->getKey())
                ->pluck('stores.id')
                ->all();
    }
}
