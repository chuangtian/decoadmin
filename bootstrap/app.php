<?php

use App\Http\Middleware\AuthenticateCodexApiToken;
use App\Http\Middleware\ConfigureAffiliatePortalSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\NormalizeShopifyAppProxyResponse;
use App\Http\Middleware\OrganizationAccessMiddleware;
use App\Http\Middleware\PermissionMiddleware;
use App\Http\Middleware\ResolveCurrentStore;
use App\Http\Middleware\ShopifyEmbeddedFrameHeaders;
use App\Http\Middleware\StoreAccessMiddleware;
use App\Http\Middleware\UseBuiltAssetsForExternalRequests;
use App\Http\Middleware\VerifyShopifyAppProxy;
use App\Http\Middleware\VerifyShopifyCheckoutToken;
use App\Http\Middleware\VerifyShopifyIdToken;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'oauth/register',
            'oauth/token',
            'oauth/revoke',
            'mcp/decoadmin',
            'shopify/webhooks/*',
            'shopify/pixels/*',
            'api/student-discounts/*',
            'api/shopify-app/webhooks',
            'api/shopify-app/referral/bootstrap',
            'api/shopify-app/referral/webhooks',
            'api/shopify-app/student-discounts/*',
            'api/shopify-app/instagram-feed/*',
            'api/shopify-app/personalization/*',
            // Meta 的回调不带 CSRF token，靠 signed_request 验签。
            'instagram-feed/meta/*',
        ]);

        $middleware->trustProxies(
            at: ['127.0.0.1', '172.16.0.0/12'],
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->alias([
            'codex.token' => AuthenticateCodexApiToken::class,
            'organization.access' => OrganizationAccessMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'store.context' => ResolveCurrentStore::class,
            'store.access' => StoreAccessMiddleware::class,
            'shopify.app-proxy' => VerifyShopifyAppProxy::class,
            'shopify.app-proxy-response' => NormalizeShopifyAppProxyResponse::class,
            'shopify.checkout-token' => VerifyShopifyCheckoutToken::class,
            'shopify.embedded-frame' => ShopifyEmbeddedFrameHeaders::class,
            'shopify.id-token' => VerifyShopifyIdToken::class,
        ]);
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            VerifyShopifyAppProxy::class,
        );

        $middleware->web(prepend: [ConfigureAffiliatePortalSession::class], append: [
            UseBuiltAssetsForExternalRequests::class,
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp/*') || $request->expectsJson(),
        );
        $exceptions->render(function (ModelNotFoundException $exception, Request $request) {
            if (! $request->is('api/codex/*') && ! $request->is('mcp/decoadmin')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'codex_resource_not_found',
                    'message' => '目标资源不存在或当前用户无权访问。',
                ],
            ], 404);
        });
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/codex/*') && ! $request->is('mcp/decoadmin')) {
                return null;
            }

            return response()->json([
                'error' => [
                    'code' => 'codex_resource_not_found',
                    'message' => '目标资源不存在或当前用户无权访问。',
                ],
            ], 404);
        });
    })->create();
