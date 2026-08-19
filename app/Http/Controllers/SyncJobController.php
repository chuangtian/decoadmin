<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSyncJobRequest;
use App\Http\Resources\SyncJobDetailResource;
use App\Http\Resources\SyncJobResource;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use App\Services\Shopify\Sync\StoreSyncStatusQueryService;
use App\Services\Sync\SyncJobService;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SyncJobController extends Controller
{
    /** @var list<string> */
    private const STATUSES = ['pending', 'queued', 'running', 'completed', 'failed', 'cancelled'];

    /** @var list<string> */
    private const TYPES = ['products', 'orders', 'customers', 'inventory'];

    /** @var list<string> */
    private const MODES = ['full', 'incremental'];

    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function index(Request $request, StoreSyncStatusQueryService $syncStatus): Response
    {
        $this->authorize('viewAny', SyncJob::class);
        $organization = $this->currentOrganization->require();
        $storeIds = $this->accessibleStoreIds($request->user(), $organization);
        $filters = $request->validate([
            'type' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:30'],
            'store_id' => ['nullable', 'integer'],
            'mode' => ['nullable', 'string', 'max:20'],
        ]);
        $type = trim((string) ($filters['type'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $storeId = (int) ($filters['store_id'] ?? 0);
        $mode = trim((string) ($filters['mode'] ?? ''));

        if (($type !== '' && ! in_array($type, self::TYPES, true))
            || ($status !== '' && ! in_array($status, self::STATUSES, true))
            || ($mode !== '' && ! in_array($mode, self::MODES, true))) {
            abort(422);
        }

        if ($storeId && ! in_array($storeId, $storeIds, true)) {
            abort(403);
        }

        $syncJobs = SyncJob::query()
            ->whereBelongsTo($organization)
            ->whereIn('store_id', $storeIds)
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($storeId > 0, fn ($query) => $query->where('store_id', $storeId))
            ->when($mode !== '', fn ($query) => $query->where('mode', $mode))
            ->with([
                'store:id,organization_id,name,shopify_domain',
                'appInstallation:id,app_id,status',
                'appInstallation.app:id,name,handle',
            ])
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $storeModels = $organization->stores()
            ->whereIn('id', $storeIds)
            ->with(['appInstallations' => fn ($query) => $query
                ->where('status', 'active')
                ->with('app:id,name,handle')])
            ->orderBy('name')
            ->get(['id', 'organization_id', 'name', 'shopify_domain']);
        $stores = $storeModels->map(fn (Store $store) => [
            'id' => $store->id,
            'name' => $store->name,
            'shopify_domain' => $store->shopify_domain,
            'can_run' => $request->user()->can('create', [SyncJob::class, $store]),
            'installations' => $store->appInstallations->map(fn (AppInstallation $installation) => [
                'id' => $installation->id,
                'status' => $installation->status,
                'app' => $installation->app ? [
                    'id' => $installation->app->id,
                    'name' => $installation->app->name,
                    'handle' => $installation->app->handle,
                ] : null,
            ])->values()->all(),
        ])
            ->values();

        return Inertia::render('Sync/Index', [
            'syncJobs' => SyncJobResource::collection($syncJobs),
            'filters' => ['type' => $type, 'status' => $status, 'store_id' => $storeId ?: null, 'mode' => $mode],
            'stores' => $stores,
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
            'modes' => self::MODES,
            'syncStatus' => $syncStatus->forStores($storeModels),
        ]);
    }

    public function store(StoreSyncJobRequest $request, SyncJobService $syncJobs): RedirectResponse
    {
        $organization = $this->currentOrganization->require();
        $store = $organization->stores()->findOrFail($request->integer('store_id'));
        $type = $request->string('type')->toString();
        $this->authorize('create', [SyncJob::class, $store, $type]);

        $installation = $request->filled('app_installation_id')
            ? AppInstallation::query()
                ->whereBelongsTo($store)
                ->where('status', 'active')
                ->find($request->integer('app_installation_id'))
            : null;

        if ($request->filled('app_installation_id') && ! $installation) {
            throw ValidationException::withMessages([
                'app_installation_id' => '请选择当前店铺的有效应用安装记录。',
            ]);
        }

        $syncJob = $syncJobs->createAndDispatch(
            $store,
            $type,
            $request->user(),
            $installation,
            $request->filled('mode') ? $request->string('mode')->toString() : 'full',
        );

        return redirect()->route('sync.show', $syncJob)
            ->with('success', '同步任务已创建并加入执行队列。');
    }

    public function show(SyncJob $syncJob): Response
    {
        $this->authorize('view', $syncJob);

        return Inertia::render('Sync/Show', [
            'syncJob' => new SyncJobDetailResource($syncJob->load([
                'store:id,organization_id,name,shopify_domain',
                'appInstallation:id,app_id,status',
                'appInstallation.app:id,name,handle',
            ])),
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
