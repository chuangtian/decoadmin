<?php

namespace App\Http\Controllers;

use App\Http\Resources\PermissionResource;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PermissionController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $this->authorize('permission', 'roles.view');
        $permissions = Permission::query()->orderBy('group')->orderBy('slug')->get();

        if ($request->expectsJson()) {
            return PermissionResource::collection($permissions)->response();
        }

        return Inertia::render('Permissions/Index', [
            'permissions' => PermissionResource::collection($permissions),
        ]);
    }
}
