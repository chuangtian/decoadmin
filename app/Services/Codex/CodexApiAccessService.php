<?php

namespace App\Services\Codex;

use App\Models\CodexApiToken;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CodexApiAccessService
{
    /** @return array{0: User, 1: Organization, 2: CodexApiToken} */
    public function context(Request $request, string $ability): array
    {
        [$user, $organization, $token] = $this->authenticatedContext($request);
        abort_unless($token->allows($ability), 403, '该 Codex 令牌没有所需能力。');

        return [$user, $organization, $token];
    }

    /** @return array{0: User, 1: Organization, 2: CodexApiToken} */
    public function authenticatedContext(Request $request): array
    {
        $user = $request->user();
        $organization = $request->attributes->get('codex_organization');
        $token = $request->attributes->get('codex_api_token');

        abort_unless($user instanceof User && $organization instanceof Organization && $token instanceof CodexApiToken, 401);

        return [$user, $organization, $token];
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    public function store(Request $request, Store $store, string $ability, string $permission): array
    {
        [$user, $organization, $store] = $this->scopedStore($request, $store, $ability);
        abort_unless($user->hasPermission($permission, $organization, $store), 403, '当前账号缺少所需后台权限。');

        return [$user, $organization, $store];
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    public function scopedStore(Request $request, Store $store, string $ability): array
    {
        [$user, $organization, , $store] = $this->scopedStoreContext($request, $store, $ability);

        return [$user, $organization, $store];
    }

    /** @return array{0: User, 1: Organization, 2: CodexApiToken, 3: Store} */
    public function scopedStoreContext(Request $request, Store $store, string $ability): array
    {
        [$user, $organization, $token] = $this->context($request, $ability);

        abort_unless((int) $store->organization_id === (int) $organization->getKey(), 404);
        abort_unless($user->canAccessStore($store), 403, '当前账号无权访问该店铺。');

        return [$user, $organization, $token, $store];
    }

    /** @return list<int> */
    public function accessibleStoreIds(User $user, Organization $organization, string $permission): array
    {
        if ($user->isSuperAdmin()) {
            return $organization->stores()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        }

        $memberStoreIds = $user->stores()
            ->where('stores.organization_id', $organization->getKey())
            ->pluck('stores.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
        if ($memberStoreIds === [] || $user->hasPermission($permission, $organization)) {
            return $memberStoreIds;
        }

        $scopedStoreIds = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $user->getKey())
            ->where('user_roles.organization_id', $organization->getKey())
            ->whereIn('user_roles.store_id', $memberStoreIds)
            ->whereNull('user_roles.deleted_at')
            ->whereNull('roles.deleted_at')
            ->whereNull('permissions.deleted_at')
            ->where(fn ($query) => $query->whereNull('user_roles.expires_at')->orWhere('user_roles.expires_at', '>', now()))
            ->where('permissions.slug', $permission)
            ->distinct()
            ->pluck('user_roles.store_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_intersect($memberStoreIds, $scopedStoreIds));
    }
}
