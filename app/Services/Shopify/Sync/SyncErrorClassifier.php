<?php

namespace App\Services\Shopify\Sync;

use App\Exceptions\ShopifyApiException;
use Throwable;

class SyncErrorClassifier
{
    public function classify(Throwable|string|SyncResult $error): string
    {
        if ($error instanceof SyncResult) {
            return (string) data_get($error->errors, '0.code', 'sync_failed');
        }

        if ($error instanceof ShopifyApiException) {
            $status = $error->context['status'] ?? null;
            $type = $error->context['error_type'] ?? null;

            return match (true) {
                in_array($status, [401, 403], true) => 'shopify_auth_invalid',
                $status === 429 || $type === 'rate_limit' => 'shopify_rate_limited',
                $type === 'timeout' => 'shopify_timeout',
                $type === 'graphql_error' => 'shopify_graphql_error',
                default => 'shopify_api_error',
            };
        }

        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        return str_contains(mb_strtolower($message), 'scope') || str_contains($message, '权限')
            ? 'shopify_scope_missing'
            : 'sync_failed';
    }
}
