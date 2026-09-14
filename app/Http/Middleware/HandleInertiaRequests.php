<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Store;
use App\Services\AppCenter\ApplicationCenterNavigationService;
use App\Services\AppCenter\ApplicationInstallationAccessService;
use App\Services\Authorization\PersonalPermissionService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $organization = app(CurrentOrganization::class)->get();
        $store = app(CurrentStore::class)->get();
        $permissions = [];
        $availableOrganizations = [];
        $applicationNavigation = [];
        $applicationAvailability = [
            ApplicationInstallationAccessService::REFERRAL => false,
        ];

        if ($user) {
            [$organization, $store] = $this->resolveContext($request, $organization, $store);

            $permissions = $user->isSuperAdmin()
                ? Permission::query()->pluck('slug')->all()
                : $user->roles()
                    ->when($organization, fn ($query) => $query
                        ->where('user_roles.organization_id', $organization->getKey())
                        ->where(function (Builder $query) use ($store): void {
                            $query->whereNull('user_roles.store_id')
                                ->when($store, fn (Builder $query) => $query->orWhere('user_roles.store_id', $store->getKey()));
                        }))
                    ->with('permissions:id,slug')
                    ->get()
                    ->flatMap->permissions
                    ->pluck('slug')
                    ->unique()
                    ->values()
                    ->all();

            if ($organization) {
                $permissions = collect($permissions)
                    ->merge(app(PersonalPermissionService::class)->personalPermissions($user, $organization))
                    ->unique()
                    ->values()
                    ->all();
            }

            $organizations = $user->isSuperAdmin()
                ? Organization::query()
                    ->where('status', 'active')
                    ->with(['stores' => fn ($query) => $query->orderBy('name')])
                    ->orderBy('name')
                    ->get()
                : $user->organizations()
                    ->where('organizations.status', 'active')
                    ->with(['stores' => fn ($query) => $query
                        ->whereHas('members', fn ($query) => $query->whereKey($user->getKey()))
                        ->orderBy('name')])
                    ->orderBy('name')
                    ->get();

            $availableOrganizations = $organizations
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'code' => $item->code,
                    'stores' => $item->stores->map(fn ($availableStore) => [
                        'id' => $availableStore->id,
                        'name' => $availableStore->name,
                        'status' => $availableStore->status,
                        'timezone' => $availableStore->timezone ?: 'UTC',
                        'currency' => $availableStore->currency,
                    ])->values()->all(),
                ])
                ->values()
                ->all();

            if ($organization && $store) {
                $applicationNavigation = app(ApplicationCenterNavigationService::class)
                    ->forStore($organization, $store, $permissions);
                $applicationAvailability[ApplicationInstallationAccessService::REFERRAL] = app(ApplicationInstallationAccessService::class)
                    ->isActive(ApplicationInstallationAccessService::REFERRAL, $organization, $store);
            }
        }

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'serverTime' => now()->utc()->toIso8601String(),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                ] : null,
                'permissions' => $permissions,
            ],
            'currentOrganization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'code' => $organization->code,
            ] : null,
            'realtime' => [
                'enabled' => config('broadcasting.default') === 'reverb'
                    && filled(config('broadcasting.connections.reverb.key')),
                'key' => (string) config('broadcasting.connections.reverb.key', ''),
            ],
            'currentStore' => $store ? [
                'id' => $store->id,
                'name' => $store->name,
                'status' => $store->status,
                'organization_id' => $store->organization_id,
                'timezone' => $store->timezone ?: 'UTC',
                'currency' => $store->currency,
            ] : null,
            'availableOrganizations' => $availableOrganizations,
            'applicationNavigation' => $applicationNavigation,
            'applicationAvailability' => $applicationAvailability,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ];
    }

    /** @return array{0: Organization|null, 1: Store|null} */
    private function resolveContext(Request $request, ?Organization $organization, ?Store $store): array
    {
        $user = $request->user();

        if (! $user) {
            return [null, null];
        }

        if (! $organization) {
            $organizationId = $request->session()->get('current_organization_id');
            $organization = $organizationId ? Organization::query()->find($organizationId) : null;

            if ($organization && ! ($user->isSuperAdmin() || $user->organizations()->whereKey($organization->getKey())->exists())) {
                $organization = null;
            }

            $organization ??= $user->isSuperAdmin()
                ? Organization::query()->where('status', 'active')->orderBy('name')->first()
                : $user->organizations()->where('organizations.status', 'active')->orderBy('name')->first();

            if ($organization) {
                app(CurrentOrganization::class)->set($organization);
                $request->session()->put('current_organization_id', $organization->getKey());
            }
        }

        if (! $store && $organization) {
            $storeId = $request->session()->get('current_store_id');
            $store = $storeId ? Store::query()->find($storeId) : null;

            if ($store && ($store->organization_id !== $organization->getKey() || ! $user->canAccessStore($store))) {
                $store = null;
            }

            $store ??= $user->isSuperAdmin()
                ? $organization->stores()->orderBy('name')->first()
                : $user->stores()->where('stores.organization_id', $organization->getKey())->orderBy('name')->first();

            if ($store) {
                app(CurrentStore::class)->set($store);
                $request->session()->put('current_store_id', $store->getKey());
            } else {
                $request->session()->forget('current_store_id');
            }
        }

        return [$organization, $store];
    }
}
