<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveRoleRequest;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Authorization\PersonalPermissionService;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function index(Request $request): Response|JsonResponse
    {
        $this->authorize('viewAny', Role::class);
        $roles = $this->currentOrganization->require()->roles()
            ->withCount(['permissions' => fn ($query) => $query
                ->whereNotIn('slug', PersonalPermissionService::BASELINE_PERMISSIONS)])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        if ($request->expectsJson()) {
            return RoleResource::collection($roles)->response();
        }

        return Inertia::render('Roles/Index', [
            'roles' => RoleResource::collection($roles),
            'permissions' => PermissionResource::collection($this->configurablePermissionsQuery()->orderBy('group')->orderBy('slug')->get()),
        ]);
    }

    public function store(SaveRoleRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Role::class);
        $validated = $request->validated();
        $role = $this->currentOrganization->require()->roles()->create([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'is_system' => false,
        ]);
        $this->syncConfigurablePermissions($role, $validated['permission_ids'] ?? []);

        return $request->expectsJson()
            ? (new RoleResource($this->loadConfigurablePermissions($role)))->response()->setStatusCode(201)
            : to_route('roles.show', $role)->with('success', '角色创建成功。');
    }

    public function show(Request $request, Role $role): Response|JsonResponse
    {
        $this->authorize('view', $role);
        $this->loadConfigurablePermissions($role);

        if ($request->expectsJson()) {
            return (new RoleResource($role))->response();
        }

        return Inertia::render('Roles/Show', [
            'role' => new RoleResource($role),
            'permissions' => PermissionResource::collection($this->configurablePermissionsQuery()->orderBy('group')->orderBy('slug')->get()),
            'canDelete' => ! $role->is_system && $request->user()->can('delete', $role),
        ]);
    }

    public function update(SaveRoleRequest $request, Role $role): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $role);
        $validated = $request->validated();
        $role->update([
            'name' => $validated['name'],
            'slug' => $role->is_system ? $role->slug : $validated['slug'],
            'description' => $validated['description'] ?? null,
        ]);
        if (array_key_exists('permission_ids', $validated)) {
            $this->syncConfigurablePermissions($role, $validated['permission_ids']);
        }

        return $request->expectsJson()
            ? (new RoleResource($this->loadConfigurablePermissions($role)))->response()
            : back()->with('success', '角色更新成功。');
    }

    public function destroy(Request $request, Role $role): RedirectResponse|JsonResponse
    {
        abort_if($role->is_system, 403);
        $this->authorize('delete', $role);
        $role->delete();

        return $request->expectsJson()
            ? response()->json(status: 204)
            : to_route('roles.index')->with('success', '角色已移除。');
    }

    public function updatePermissions(UpdateRolePermissionsRequest $request, Role $role): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $role);
        $this->syncConfigurablePermissions($role, $request->validated('permission_ids'));

        return $request->expectsJson()
            ? (new RoleResource($this->loadConfigurablePermissions($role)))->response()
            : back()->with('success', '权限更新成功。');
    }

    private function configurablePermissionsQuery(): Builder
    {
        return Permission::query()
            ->whereNotIn('slug', PersonalPermissionService::BASELINE_PERMISSIONS);
    }

    /** @param list<int> $permissionIds */
    private function syncConfigurablePermissions(Role $role, array $permissionIds): void
    {
        $configurableIds = $this->configurablePermissionsQuery()
            ->whereKey($permissionIds)
            ->pluck('id');
        $baselineIds = Permission::query()
            ->whereIn('slug', PersonalPermissionService::BASELINE_PERMISSIONS)
            ->pluck('id');

        $role->permissions()->sync($configurableIds->merge($baselineIds)->unique()->all());
    }

    private function loadConfigurablePermissions(Role $role): Role
    {
        return $role->load([
            'permissions' => fn ($query) => $query
                ->whereNotIn('slug', PersonalPermissionService::BASELINE_PERMISSIONS),
        ]);
    }
}
