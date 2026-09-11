<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\Store;
use App\Services\AppCenter\ApplicationInstallationAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InstalledApplicationMiddleware
{
    public function __construct(private ApplicationInstallationAccessService $installations) {}

    public function handle(Request $request, Closure $next, string $application): Response
    {
        $organization = $request->route('organization');
        $store = $request->route('store');

        abort_unless(
            $organization instanceof Organization
                && $store instanceof Store
                && $this->installations->isActive($application, $organization, $store),
            403,
            '当前店铺尚未安装或启用该应用。',
        );

        return $next($request);
    }
}
