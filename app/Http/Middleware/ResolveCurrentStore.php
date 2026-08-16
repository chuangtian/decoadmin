<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveCurrentStore
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentStore $currentStore,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = $this->currentOrganization->require();
        abort_unless($user, 401);

        $storeId = $request->header('X-Store-ID') ?? $request->session()->get('current_store_id');
        $store = $storeId ? Store::query()->find($storeId) : null;

        if ($store && ($store->organization_id !== $organization->getKey() || ! $user->canAccessStore($store))) {
            $store = null;
            $request->session()->forget('current_store_id');
        }

        $store ??= $user->isSuperAdmin()
            ? $organization->stores()->orderBy('name')->first()
            : $user->stores()->where('stores.organization_id', $organization->getKey())->orderBy('name')->first();

        if ($store) {
            $this->currentStore->set($store);
            $request->session()->put('current_store_id', $store->getKey());
        } else {
            $this->currentStore->clear();
        }

        return $next($request);
    }
}
