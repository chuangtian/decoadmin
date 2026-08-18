<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignUserRolesRequest;
use App\Http\Requests\AssignUserStoresRequest;
use App\Http\Requests\SaveUserRequest;
use App\Http\Requests\UpdateUserAvatarRequest;
use App\Http\Resources\RoleResource;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\UserAvatarService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class UserController extends Controller
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private UserAvatarService $avatars,
    ) {}

    public function index(Request $request): Response|JsonResponse
    {
        $this->authorize('viewAny', User::class);
        $organization = $this->currentOrganization->require();
        $users = $organization->users()
            ->with([
                'roles' => fn ($query) => $query->where('user_roles.organization_id', $organization->getKey()),
                'stores' => fn ($query) => $query->where('stores.organization_id', $organization->getKey()),
            ])
            ->latest('users.created_at')
            ->paginate(20)
            ->withQueryString();

        if ($request->expectsJson()) {
            return UserResource::collection($users)->response();
        }

        return Inertia::render('Users/Index', [
            'users' => UserResource::collection($users),
            ...$this->options(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Users/Form', $this->options());
    }

    public function store(SaveUserRequest $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', User::class);
        $organization = $this->currentOrganization->require();
        $validated = $request->validated();
        $avatarUrl = $request->hasFile('avatar')
            ? $this->avatars->store($request->file('avatar'))
            : null;

        try {
            $user = DB::transaction(function () use ($validated, $organization, $avatarUrl): User {
                $user = User::query()->create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'status' => $validated['status'] ?? 'active',
                    'avatar_url' => $avatarUrl,
                ]);
                $organization->users()->attach($user, [
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
                $this->syncRoles($user, $validated['role_ids'] ?? []);
                $this->syncStores($user, $validated['store_ids'] ?? []);

                return $user;
            });
        } catch (Throwable $exception) {
            $this->avatars->delete($avatarUrl);

            throw $exception;
        }

        if ($request->expectsJson()) {
            return (new UserResource($user->load(['roles', 'stores'])))->response()->setStatusCode(201);
        }

        return to_route('users.index')->with('success', '用户创建成功。');
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);
        $organization = $this->currentOrganization->require();

        return Inertia::render('Users/Form', [
            'user' => new UserResource($user->load([
                'roles' => fn ($query) => $query->where('user_roles.organization_id', $organization->getKey()),
                'stores' => fn ($query) => $query->where('stores.organization_id', $organization->getKey()),
            ])),
            ...$this->options(),
        ]);
    }

    public function show(Request $request, User $user): JsonResponse|RedirectResponse
    {
        $this->authorize('view', $user);

        if (! $request->expectsJson()) {
            return to_route('users.edit', $user);
        }

        $organization = $this->currentOrganization->require();

        return (new UserResource($user->load([
            'roles' => fn ($query) => $query->where('user_roles.organization_id', $organization->getKey()),
            'stores' => fn ($query) => $query->where('stores.organization_id', $organization->getKey()),
        ])))->response();
    }

    public function update(SaveUserRequest $request, User $user): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $user);
        $validated = $request->validated();
        $previousAvatarUrl = $user->avatar_url;
        $newAvatarUrl = $request->hasFile('avatar')
            ? $this->avatars->store($request->file('avatar'))
            : null;
        $shouldRemoveAvatar = (bool) ($validated['remove_avatar'] ?? false);

        try {
            DB::transaction(function () use ($user, $validated, $newAvatarUrl, $shouldRemoveAvatar): void {
                $user->fill([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'status' => $validated['status'] ?? $user->status,
                ]);
                if ($newAvatarUrl) {
                    $user->avatar_url = $newAvatarUrl;
                } elseif ($shouldRemoveAvatar) {
                    $user->avatar_url = null;
                }
                if (! empty($validated['password'])) {
                    $user->password = $validated['password'];
                }
                $user->save();

                if (array_key_exists('role_ids', $validated)) {
                    $this->syncRoles($user, $validated['role_ids']);
                }
                if (array_key_exists('store_ids', $validated)) {
                    $this->syncStores($user, $validated['store_ids']);
                }
            });
        } catch (Throwable $exception) {
            $this->avatars->delete($newAvatarUrl);

            throw $exception;
        }

        if (($newAvatarUrl || $shouldRemoveAvatar) && $previousAvatarUrl !== $newAvatarUrl) {
            $this->avatars->delete($previousAvatarUrl);
        }

        if ($request->expectsJson()) {
            return (new UserResource($user->fresh()->load(['roles', 'stores'])))->response();
        }

        return to_route('users.index')->with('success', '用户更新成功。');
    }

    public function updateAvatar(UpdateUserAvatarRequest $request, User $user): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $user);
        $this->avatars->replace($user, $request->file('avatar'));
        $user->refresh();

        return $request->expectsJson()
            ? (new UserResource($user))->response()
            : back()->with('success', '头像已自动保存。');
    }

    public function destroyAvatar(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $user);
        $this->avatars->remove($user);

        return $request->expectsJson()
            ? response()->json(['data' => ['id' => $user->getKey(), 'avatar_url' => null]])
            : back()->with('success', '头像已移除。');
    }

    public function destroy(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $user);
        $organization = $this->currentOrganization->require();

        DB::transaction(function () use ($organization, $user): void {
            DB::table('organization_users')
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->update(['status' => 'inactive', 'deleted_at' => now(), 'updated_at' => now()]);
            DB::table('user_roles')
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->update(['deleted_at' => now(), 'updated_at' => now()]);
            DB::table('store_members')
                ->where('user_id', $user->getKey())
                ->whereIn('store_id', $organization->stores()->pluck('id'))
                ->update(['status' => 'inactive', 'deleted_at' => now(), 'updated_at' => now()]);

            if (! $user->organizations()->exists()) {
                $user->delete();
            }
        });

        return $request->expectsJson()
            ? response()->json(status: 204)
            : to_route('users.index')->with('success', '用户已从当前组织移除。');
    }

    public function assignRoles(AssignUserRolesRequest $request, User $user): JsonResponse|RedirectResponse
    {
        $this->authorize('assignRole', $user);
        $validated = $request->validated();
        if (isset($validated['assignments'])) {
            $this->syncRoleAssignments($user, $validated['assignments']);
        } else {
            $this->syncRoles($user, $validated['role_ids']);
        }

        return $request->expectsJson()
            ? (new UserResource($user->load('roles')))->response()
            : back()->with('success', '角色更新成功。');
    }

    public function assignStores(AssignUserStoresRequest $request, User $user): JsonResponse|RedirectResponse
    {
        $this->authorize('update', $user);
        $this->syncStores($user, $request->validated('store_ids'));

        return $request->expectsJson()
            ? (new UserResource($user->load('stores')))->response()
            : back()->with('success', '店铺访问范围更新成功。');
    }

    private function options(): array
    {
        $organization = $this->currentOrganization->require();
        $roles = $organization->roles()->orderBy('name');

        if (! request()->user()?->isSuperAdmin()) {
            $roles->where('slug', '!=', 'super-admin');
        }

        return [
            'roles' => RoleResource::collection($roles->get()),
            'stores' => $organization->stores()->orderBy('name')->get(['id', 'name', 'status'])
                ->map(fn (Store $store) => [
                    'id' => $store->id,
                    'name' => $store->name,
                    'status' => $store->status,
                ])->values(),
        ];
    }

    /** @param list<int> $roleIds */
    private function syncRoles(User $user, array $roleIds): void
    {
        $organization = $this->currentOrganization->require();
        $roles = Role::query()->whereBelongsTo($organization)->whereKey($roleIds);
        if (! request()->user()?->isSuperAdmin()) {
            $roles->where('slug', '!=', 'super-admin');
        }
        $allowed = $roles->pluck('id')->all();

        DB::table('user_roles')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('store_id')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        foreach ($allowed as $roleId) {
            $assignment = DB::table('user_roles')
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->where('role_id', $roleId)
                ->whereNull('store_id')
                ->first();
            $values = ['deleted_at' => null, 'expires_at' => null, 'updated_at' => now()];

            if ($assignment) {
                DB::table('user_roles')->where('id', $assignment->id)->update($values);
            } else {
                DB::table('user_roles')->insert([
                    ...$values,
                    'organization_id' => $organization->getKey(),
                    'store_id' => null,
                    'user_id' => $user->getKey(),
                    'role_id' => $roleId,
                    'granted_by' => request()->user()?->getKey(),
                    'created_at' => now(),
                ]);
            }
        }
    }

    /** @param list<array{role_id: int, store_id?: int|null}> $assignments */
    private function syncRoleAssignments(User $user, array $assignments): void
    {
        $organization = $this->currentOrganization->require();
        $roleQuery = Role::query()->whereBelongsTo($organization);
        if (! request()->user()?->isSuperAdmin()) {
            $roleQuery->where('slug', '!=', 'super-admin');
        }
        $allowedRoleIds = $roleQuery->pluck('id')->all();
        $allowedStoreIds = $user->stores()
            ->where('stores.organization_id', $organization->getKey())
            ->pluck('stores.id')
            ->all();
        $assignments = collect($assignments)
            ->filter(fn (array $assignment) => in_array($assignment['role_id'], $allowedRoleIds, true)
                && (empty($assignment['store_id']) || in_array($assignment['store_id'], $allowedStoreIds, true)))
            ->unique(fn (array $assignment) => $assignment['role_id'].':'.($assignment['store_id'] ?? 'organization'));

        DB::table('user_roles')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->update(['deleted_at' => now(), 'updated_at' => now()]);

        foreach ($assignments as $assignment) {
            $storeId = $assignment['store_id'] ?? null;
            $query = DB::table('user_roles')
                ->where('organization_id', $organization->getKey())
                ->where('user_id', $user->getKey())
                ->where('role_id', $assignment['role_id']);
            $storeId ? $query->where('store_id', $storeId) : $query->whereNull('store_id');
            $existing = $query->first();
            $values = ['deleted_at' => null, 'expires_at' => null, 'updated_at' => now()];

            if ($existing) {
                DB::table('user_roles')->where('id', $existing->id)->update($values);
            } else {
                DB::table('user_roles')->insert([
                    ...$values,
                    'organization_id' => $organization->getKey(),
                    'store_id' => $storeId,
                    'user_id' => $user->getKey(),
                    'role_id' => $assignment['role_id'],
                    'granted_by' => request()->user()?->getKey(),
                    'created_at' => now(),
                ]);
            }
        }
    }

    /** @param list<int> $storeIds */
    private function syncStores(User $user, array $storeIds): void
    {
        $organization = $this->currentOrganization->require();
        $allowed = Store::query()->whereBelongsTo($organization)->whereKey($storeIds)->pluck('id')->all();
        $organizationStoreIds = $organization->stores()->pluck('id');

        DB::table('store_members')
            ->where('user_id', $user->getKey())
            ->whereIn('store_id', $organizationStoreIds)
            ->update(['status' => 'inactive', 'deleted_at' => now(), 'updated_at' => now()]);

        foreach ($allowed as $storeId) {
            $membership = DB::table('store_members')
                ->where('store_id', $storeId)
                ->where('user_id', $user->getKey())
                ->first();
            $values = ['status' => 'active', 'deleted_at' => null, 'updated_at' => now()];

            if ($membership) {
                DB::table('store_members')->where('id', $membership->id)->update($values);
            } else {
                DB::table('store_members')->insert([
                    ...$values,
                    'store_id' => $storeId,
                    'user_id' => $user->getKey(),
                    'invited_by' => request()->user()?->getKey(),
                    'joined_at' => now(),
                    'created_at' => now(),
                ]);
            }
        }
    }
}
