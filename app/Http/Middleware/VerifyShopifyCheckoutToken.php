<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyCheckoutToken
{
    /**
     * Validate a Shopify Checkout UI Extension Session Token.
     *
     * Checkout tokens use the app client secret but, unlike App Home tokens,
     * their signed `dest` claim can be a bare myshopify domain and `iss` is not
     * guaranteed. The trusted shop is therefore derived from `dest`, never from
     * a request-supplied Organization, Store, or customer value.
     */
    public function handle(Request $request, Closure $next, string $configKey = 'personalization'): Response
    {
        $token = $request->bearerToken();
        $clientId = (string) config($configKey.'.active.client_id', '');
        $clientSecret = (string) config($configKey.'.active.client_secret', '');
        if (! is_string($token) || $token === '' || $clientId === '' || $clientSecret === '') {
            return $this->unauthorized('missing_token_or_configuration');
        }

        $segments = explode('.', $token);
        if (count($segments) !== 3) {
            return $this->unauthorized('invalid_token_segments');
        }

        try {
            $header = json_decode($this->decodeSegment($segments[0]), true, 8, JSON_THROW_ON_ERROR);
            $claims = json_decode($this->decodeSegment($segments[1]), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->unauthorized('invalid_token_encoding');
        }
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? null) !== 'HS256') {
            return $this->unauthorized('invalid_token_header');
        }

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $segments[0].'.'.$segments[1], $clientSecret, true));
        if (! hash_equals($expected, $segments[2])) {
            return $this->unauthorized('signature_mismatch');
        }

        $shop = $this->shopFromDestination($claims['dest'] ?? null);
        $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
        $now = now()->timestamp;
        $leeway = (int) config($configKey.'.id_token_leeway_seconds', 5);
        if ($shop === null) {
            return $this->unauthorized('invalid_destination');
        }
        if (! in_array($clientId, $audiences, true)) {
            return $this->unauthorized('audience_mismatch');
        }
        if (! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] <= $now - $leeway) {
            return $this->unauthorized('expired_or_missing_expiry');
        }
        if (! is_numeric($claims['nbf'] ?? null) || (int) $claims['nbf'] > $now + $leeway) {
            return $this->unauthorized('invalid_not_before');
        }
        if (isset($claims['iat']) && (! is_numeric($claims['iat']) || (int) $claims['iat'] > $now + $leeway)) {
            return $this->unauthorized('invalid_issued_at');
        }

        $issuer = rtrim((string) ($claims['iss'] ?? ''), '/');
        if ($issuer !== '' && ! in_array($issuer, ['https://'.$shop, 'https://'.$shop.'/admin'], true)) {
            return $this->unauthorized('issuer_mismatch');
        }

        $request->attributes->set('shopify_checkout_token_claims', $claims);
        $request->attributes->set('shopify_shop', $shop);

        return $next($request);
    }

    private function shopFromDestination(mixed $destination): ?string
    {
        if (! is_string($destination)) {
            return null;
        }
        $normalized = strtolower(trim($destination));
        $normalized = preg_replace('#^https?://#', '', $normalized) ?? '';
        $normalized = rtrim($normalized, '/');

        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $normalized) === 1
            ? $normalized
            : null;
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

    private function unauthorized(string $reason): JsonResponse
    {
        Log::notice('Shopify Checkout session token rejected.', [
            'reason' => $reason,
        ]);

        return response()->json(['error' => [
            'code' => 'INVALID_SHOPIFY_CHECKOUT_TOKEN',
            'message' => 'Shopify Checkout 身份令牌无效或已过期。',
        ]], 401, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
