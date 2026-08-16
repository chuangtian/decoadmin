<?php

namespace App\Http\Middleware;

use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentStore $currentStore,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_unless(
            $user->hasPermission($permission, $this->currentOrganization->get(), $this->currentStore->get()),
            403,
        );

        return $next($request);
    }
}
