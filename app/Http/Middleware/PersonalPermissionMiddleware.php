<?php

namespace App\Http\Middleware;

use App\Services\Authorization\PersonalPermissionService;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PersonalPermissionMiddleware
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private PersonalPermissionService $permissions,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $organization = $this->currentOrganization->get();
        abort_unless($user && $organization, 401);
        abort_unless($this->permissions->allows($user, $organization, $permission), 403);

        return $next($request);
    }
}
