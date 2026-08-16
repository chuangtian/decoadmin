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
            ?? $request->input('organization_id');

        $organization = $candidate instanceof Organization
            ? $candidate
            : ($candidate ? Organization::query()->find($candidate) : $user->organizations()->first());

        abort_unless($organization, 422, 'No current organization is available.');
        abort_unless($user->isSuperAdmin() || $user->organizations()->whereKey($organization->getKey())->exists(), 403);

        $this->currentOrganization->set($organization);

        return $next($request);
    }
}
