<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\OrganizationAccessMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\ResolveCurrentStore;
use App\Http\Middleware\StoreAccessMiddleware;
use App\Http\Middleware\UseBuiltAssetsForExternalRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: ['127.0.0.1', '172.16.0.0/12'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'organization.access' => OrganizationAccessMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'store.context' => ResolveCurrentStore::class,
            'store.access' => StoreAccessMiddleware::class,
        ]);

        $middleware->web(append: [
            UseBuiltAssetsForExternalRequests::class,
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
