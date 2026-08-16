<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UseBuiltAssetsForExternalRequests
{
    public function __construct(private Vite $vite) {}

    public function handle(Request $request, Closure $next): Response
    {
        $configuredHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $useBuiltAssets = $request->isSecure()
            && is_string($configuredHost)
            && strtolower($request->getHost()) === strtolower($configuredHost);

        $this->vite->useHotFile($useBuiltAssets
            ? storage_path('framework/vite-external.hot')
            : public_path('hot'));

        return $next($request);
    }
}
