<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StoreAccessMiddleware
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentStore $currentStore,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $candidate = $request->route('store');
        $store = $candidate instanceof Store ? $candidate : Store::query()->find($candidate);
        abort_unless($store, 404);

        $organization = $this->currentOrganization->get();
        abort_unless($organization && $store->organization_id === $organization->getKey(), 403);
        abort_unless($user->canAccessStore($store), 403);

        $this->currentStore->set($store);

        return $next($request);
    }
}
