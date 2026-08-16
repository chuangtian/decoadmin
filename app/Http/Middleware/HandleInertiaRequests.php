<?php

namespace App\Http\Middleware;

use App\Models\Permission;
use App\Support\CurrentOrganization;
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
        $permissions = [];

        if ($user) {
            $permissions = $user->isSuperAdmin()
                ? Permission::query()->pluck('slug')->all()
                : $user->roles()
                    ->when($organization, fn ($query) => $query
                        ->where('user_roles.organization_id', $organization->getKey())
                        ->whereNull('user_roles.store_id'))
                    ->with('permissions:id,slug')
                    ->get()
                    ->flatMap->permissions
                    ->pluck('slug')
                    ->unique()
                    ->values()
                    ->all();
        }

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                'permissions' => $permissions,
            ],
            'currentOrganization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'code' => $organization->code,
            ] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
