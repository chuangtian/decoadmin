<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class OrganizationAccessMiddleware
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user, 401);

        $candidate = $request->route('organization')
            ?? $request->header('X-Organization-ID')
            ?? $request->input('organization_id')
            ?? $request->session()->get('current_organization_id');

        $organization = $candidate instanceof Organization
            ? $candidate
            : ($candidate ? Organization::query()->find($candidate) : null);

        if ($organization && ! ($user->isSuperAdmin() || $user->organizations()->whereKey($organization->getKey())->exists())) {
            $organization = null;
            $request->session()->forget(['current_organization_id', 'current_store_id']);
        }

        $organization ??= $user->isSuperAdmin()
            ? Organization::query()->where('status', 'active')->orderBy('name')->first()
            : $user->organizations()->where('organizations.status', 'active')->orderBy('name')->first();

        abort_unless($organization, 422, 'No current organization is available.');
        abort_unless($user->isSuperAdmin() || $user->organizations()->whereKey($organization->getKey())->exists(), 403);

        $this->currentOrganization->set($organization);
        $request->session()->put('current_organization_id', $organization->getKey());

        return $next($request);
    }
}
