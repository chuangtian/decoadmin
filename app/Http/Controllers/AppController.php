<?php

namespace App\Http\Controllers;

use App\Http\Resources\AppInstallationResource;
use App\Http\Resources\AppResource;
use App\Models\App as ShopifyApp;
use App\Services\AppCenter\ApplicationCenterQueryService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AppController extends Controller
{
    public function __construct(private ApplicationCenterQueryService $applicationCenter) {}

    public function index(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): Response {
        $this->authorize('viewAny', ShopifyApp::class);
        $organization = $currentOrganization->require();
        $user = $request->user();
        abort_unless($user, 401);
        $store = $currentStore->get();
        if ($store) {
            $this->authorize('view', $store);
            abort_unless($store->organization_id === $organization->id, 403);
        }

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:30'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $registry = $this->applicationCenter->registry($organization, $user, $store, $search, $status);

        return Inertia::render('Apps/Index', [
            'apps' => AppResource::collection($registry['apps']),
            'filters' => ['search' => $search, 'status' => $status],
            'statusOptions' => $registry['status_options'],
        ]);
    }

    public function show(
        ShopifyApp $app,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): Response {
        $this->authorize('view', $app);
        $organization = $currentOrganization->require();
        $store = $currentStore->get();
        if ($store) {
            $this->authorize('view', $store);
            abort_unless($store->organization_id === $organization->id, 403);
        }

        $installations = $this->applicationCenter->appInstallationsForStore($app, $store);
        $app->setAttribute(
            'current_store_installation_status',
            $installations->first()?->status ?? 'not_installed',
        );

        return Inertia::render('Apps/Show', [
            'app' => new AppResource($app),
            'installations' => AppInstallationResource::collection($installations),
        ]);
    }
}
