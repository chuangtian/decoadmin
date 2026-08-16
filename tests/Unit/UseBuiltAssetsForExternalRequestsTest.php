<?php

namespace Tests\Unit;

use App\Http\Middleware\UseBuiltAssetsForExternalRequests;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class UseBuiltAssetsForExternalRequestsTest extends TestCase
{
    public function test_https_external_host_uses_build_while_local_http_uses_vite_hot_file(): void
    {
        config()->set('app.url', 'http://localhost:8000');
        config()->set('shopify.app_url', 'https://example.trycloudflare.com');
        $vite = app(Vite::class);
        $middleware = new UseBuiltAssetsForExternalRequests($vite);
        $next = fn () => new Response;

        $middleware->handle(Request::create('https://example.trycloudflare.com/login'), $next);
        $this->assertSame(storage_path('framework/vite-external.hot'), $vite->hotFile());

        $middleware->handle(Request::create('http://localhost:8000/login'), $next);
        $this->assertSame(public_path('hot'), $vite->hotFile());
    }
}
