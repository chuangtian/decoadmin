<?php

namespace App\Services\Authorization;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;

class PersonalPermissionService
{
    private const GROUPS = ['design_requests', 'technical_requests', 'request_approvals', 'expense_requests', 'expense_claims'];

    /** @return list<string> */
    public function personalPermissions(User $user, Organization $organization): array
    {
        if (! $user->isSuperAdmin() && ! $user->organizations()->whereKey($organization->getKey())->exists()) {
            return [];
        }

        if ($user->isSuperAdmin()) {
            return Permission::query()
                ->whereIn('group', self::GROUPS)
                ->pluck('slug')
                ->all();
        }

        return $user->roles()
            ->where('user_roles.organization_id', $organization->getKey())
            ->with(['permissions' => fn ($query) => $query->whereIn('group', self::GROUPS)])
            ->get()
            ->flatMap->permissions
            ->pluck('slug')
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function designRequestPermissions(User $user, Organization $organization): array
    {
        return collect($this->personalPermissions($user, $organization))
            ->filter(fn (string $permission) => str_starts_with($permission, 'design_requests.'))
            ->values()
            ->all();
    }

    public function allows(User $user, Organization $organization, string $permission): bool
    {
        return in_array($permission, $this->personalPermissions($user, $organization), true);
    }
}
