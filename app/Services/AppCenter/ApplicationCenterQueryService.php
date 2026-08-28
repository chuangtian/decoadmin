<?php

namespace App\Services\AppCenter;

use App\Models\App as ShopifyApp;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ApplicationCenterQueryService
{
    /** @var list<string> */
    public const ACTIVE_INSTALLATION_STATUSES = ['active'];

    /** @var list<string> */
    public const CONFIGURABLE_INSTALLATION_STATUSES = ['active', 'pending', 'disabled'];

    /**
     * @return array{apps: LengthAwarePaginator, status_options: Collection<int, string>}
     */
    public function registry(
        Organization $organization,
        User $user,
        ?Store $currentStore,
        string $search,
        string $status,
    ): array {
        $storeIds = $this->accessibleStoreIds($user, $organization);
        $registry = ShopifyApp::query()->where(function (Builder $query) use ($organization, $storeIds): void {
            $query->whereBelongsTo($organization)
                ->orWhere(function (Builder $query) use ($organization, $storeIds): void {
                    $query->whereNull('organization_id')
                        ->whereHas('installations', fn (Builder $query) => $query
                            ->whereIn('store_id', $storeIds)
                            ->whereHas('store', fn (Builder $query) => $query->whereBelongsTo($organization)));
                });
        });

        $statusOptions = (clone $registry)
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status')
            ->values();

        $apps = (clone $registry)
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('handle', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            }))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->when($currentStore, fn (Builder $query) => $query->addSelect([
                'current_store_installation_status' => AppInstallation::query()
                    ->select('status')
                    ->whereColumn('app_id', 'apps.id')
                    ->where('store_id', $currentStore->id)
                    ->latest('id')
                    ->limit(1),
            ]))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return ['apps' => $apps, 'status_options' => $statusOptions];
    }

    /** @return Collection<int, AppInstallation> */
    public function configurationInstallations(Store $store): Collection
    {
        return AppInstallation::query()
            ->where('store_id', $store->id)
            ->whereIn('status', self::CONFIGURABLE_INSTALLATION_STATUSES)
            ->whereHas('app', fn (Builder $query) => $query
                ->where('status', 'active')
                ->where(function (Builder $query) use ($store): void {
                    $query->whereNull('organization_id')
                        ->orWhere('organization_id', $store->organization_id);
                }))
            ->with([
                'app:id,organization_id,name,handle,status,description,settings',
                'store:id,organization_id,name,shopify_domain,status',
                'store.organization:id,name',
                'shopifyConnection:id,store_id,status',
            ])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->latest('installed_at')
            ->get()
            ->sortBy(fn (AppInstallation $installation): string => mb_strtolower((string) $installation->app?->name))
            ->values();
    }

    /** @return Collection<int, AppInstallation> */
    public function appInstallationsForStore(ShopifyApp $app, ?Store $store): Collection
    {
        if (! $store) {
            return collect();
        }

        return $app->installations()
            ->where('store_id', $store->id)
            ->with('store:id,organization_id,name,shopify_domain,status,timezone')
            ->latest('id')
            ->get();
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
