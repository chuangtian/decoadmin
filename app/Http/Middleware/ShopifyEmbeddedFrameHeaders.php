<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 允许指定 Shopify 店铺把本页面嵌进 Shopify Admin 的 iframe。
 *
 * Shopify 要求内嵌应用按店铺下发 frame-ancestors，只放行「当前这家店 + Shopify Admin」。
 * shop 来自查询串，所以必须先按 myshopify 域名格式校验，避免把任意内容拼进响应头。
 * 校验不过就退回 'none'：这种请求不可能来自 Shopify Admin。
 *
 * 同时显式移除 X-Frame-Options —— 它与 frame-ancestors 语义冲突，部分浏览器会以更严格
 * 的那个为准，导致 iframe 直接白屏。
 */
class ShopifyEmbeddedFrameHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $shop = strtolower(trim((string) $request->query('shop', '')));
        $ancestors = preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) === 1
            ? "https://{$shop} https://admin.shopify.com"
            : "'none'";

        $response->headers->set('Content-Security-Policy', "frame-ancestors {$ancestors};");
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
