<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveRoleRequest;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Http\Resources\PermissionResource;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Support\CurrentOrganization;
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
            ->withCount('permissions')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        if ($request->expectsJson()) {
            return RoleResource::collection($roles)->response();
        }

        return Inertia::render('Roles/Index', [
            'roles' => RoleResource::collection($roles),
            'permissions' => PermissionResource::collection(Permission::query()->orderBy('group')->orderBy('slug')->get()),
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
        $role->permissions()->sync($validated['permission_ids'] ?? []);

        return $request->expectsJson()
            ? (new RoleResource($role->load('permissions')))->response()->setStatusCode(201)
            : to_route('roles.show', $role)->with('success', '角色创建成功。');
    }

    public function show(Request $request, Role $role): Response|JsonResponse
    {
        $this->authorize('view', $role);
        $role->load('permissions');

        if ($request->expectsJson()) {
            return (new RoleResource($role))->response();
        }

        return Inertia::render('Roles/Show', [
            'role' => new RoleResource($role),
            'permissions' => PermissionResource::collection(Permission::query()->orderBy('group')->orderBy('slug')->get()),
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
            $role->permissions()->sync($validated['permission_ids']);
        }

        return $request->expectsJson()
            ? (new RoleResource($role->load('permissions')))->response()
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
        $role->permissions()->sync($request->validated('permission_ids'));

        return $request->expectsJson()
            ? (new RoleResource($role->load('permissions')))->response()
            : back()->with('success', '权限更新成功。');
    }
}
