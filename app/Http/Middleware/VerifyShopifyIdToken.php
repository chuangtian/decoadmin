<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyIdToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $clientId = (string) config('student_discount.active.client_id', '');
        $clientSecret = (string) config('student_discount.active.client_secret', '');
        if (! is_string($token) || $token === '' || $clientId === '' || $clientSecret === '') {
            return $this->unauthorized();
        }

        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return $this->unauthorized();
        }

        try {
            $header = json_decode($this->decodeSegment($segments[0]), true, 8, JSON_THROW_ON_ERROR);
            $claims = json_decode($this->decodeSegment($segments[1]), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->unauthorized();
        }
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'HS256') {
            return $this->unauthorized();
        }

        $expectedSignature = $this->base64UrlEncode(hash_hmac('sha256', $segments[0].'.'.$segments[1], $clientSecret, true));
        if (! hash_equals($expectedSignature, $segments[2])) {
            return $this->unauthorized();
        }

        $shop = strtolower(trim((string) $request->query('shop', '')));
        $destination = rtrim((string) ($claims['dest'] ?? ''), '/');
        $issuer = rtrim((string) ($claims['iss'] ?? ''), '/');
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        $now = now()->timestamp;
        $leeway = (int) config('student_discount.id_token_leeway_seconds', 5);

        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) !== 1
            || $destination !== 'https://'.$shop
            || $issuer !== 'https://'.$shop.'/admin'
            || ! in_array($clientId, $audiences, true)
            || ! is_numeric($claims['exp'] ?? null)
            || (int) $claims['exp'] <= $now - $leeway
            || ! is_numeric($claims['nbf'] ?? null)
            || (int) $claims['nbf'] > $now + $leeway
            || (isset($claims['iat']) && (! is_numeric($claims['iat']) || (int) $claims['iat'] > $now + $leeway))) {
            return $this->unauthorized();
        }

        $request->attributes->set('shopify_id_token', $token);
        $request->attributes->set('shopify_id_token_claims', $claims);
        $request->attributes->set('shopify_shop', $shop);

        return $next($request);
    }

    private function decodeSegment(string $segment): string
    {
        $padding = (4 - strlen($segment) % 4) % 4;
        $decoded = base64_decode(strtr($segment.str_repeat('=', $padding), '-_', '+/'), true);
        if (! is_string($decoded)) {
            throw new JsonException('Invalid base64url segment.');
        }

        return $decoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['error' => [
            'code' => 'INVALID_SHOPIFY_ID_TOKEN',
            'message' => 'Shopify 身份令牌无效或已过期。',
        ]], 401, ['X-Shopify-Retry-Invalid-Session-Request' => '1']);
    }
}
