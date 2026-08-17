<?php

namespace App\Services\Shopify;

class ShopifyWebhookHmacValidator
{
    public function validate(string $rawPayload, ?string $providedHmac, string $clientSecret): bool
    {
        if (! is_string($providedHmac) || $providedHmac === '' || $clientSecret === '') {
            return false;
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $rawPayload, $clientSecret, true));

        return hash_equals($calculatedHmac, $providedHmac);
    }
}
