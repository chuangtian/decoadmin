<?php

namespace App\Services\Shopify;

class ShopifyOAuthHmacValidator
{
    /** @param array<string, mixed> $query */
    public function validate(array $query, string $clientSecret): bool
    {
        $providedHmac = $query['hmac'] ?? null;

        if (! is_string($providedHmac) || ! preg_match('/^[a-f0-9]{64}$/i', $providedHmac)) {
            return false;
        }

        unset($query['hmac'], $query['signature']);
        ksort($query, SORT_STRING);

        $message = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $calculatedHmac = hash_hmac('sha256', $message, $clientSecret);

        return hash_equals($calculatedHmac, strtolower($providedHmac));
    }
}
