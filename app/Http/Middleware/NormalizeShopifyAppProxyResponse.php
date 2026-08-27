<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeShopifyAppProxyResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $status = $response->getStatusCode();

        if (! $response instanceof JsonResponse
            || $status < 400
            || ! $this->isExpectedShopifyProxyRequest($request)) {
            return $response;
        }

        $payload = $response->getData(true);
        if (! is_array($payload) || ! is_array($payload['error'] ?? null)) {
            return $response;
        }

        $payload['error']['status'] = $status;
        $response->setData($payload);
        $response->setStatusCode(Response::HTTP_OK);
        $response->headers->set('X-Student-Discount-Status', (string) $status);

        return $response;
    }

    private function isExpectedShopifyProxyRequest(Request $request): bool
    {
        $pathPrefix = rtrim((string) $request->query('path_prefix', ''), '/');
        $expectedPath = rtrim((string) config('student_discount.active.proxy_path', ''), '/');

        return $pathPrefix !== '' && $expectedPath !== '' && hash_equals($expectedPath, $pathPrefix);
    }
}
