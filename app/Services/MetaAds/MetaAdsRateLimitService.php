<?php

namespace App\Services\MetaAds;

use App\Models\Store;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Response;

class MetaAdsRateLimitService
{
    private const RATE_LIMIT_ERROR_CODES = [4, 17, 32, 613, 80004];

    public function __construct(private Repository $cache) {}

    public function wait(Store $store): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $blockedUntil = max(
            (int) $this->cache->get($this->appKey(), 0),
            (int) $this->cache->get($this->storeKey($store), 0),
        );
        $seconds = min(
            max(0, (int) config('services.meta_ads.usage_max_wait_seconds', 30)),
            max(0, $blockedUntil - time()),
        );

        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    public function observe(Store $store, Response $response): void
    {
        $this->applyUsage($this->appKey(), $this->usage($response->header('X-App-Usage')));
        $storeUsage = max(
            $this->usage($response->header('X-Ad-Account-Usage')),
            $this->usage($response->header('X-Business-Use-Case-Usage')),
        );
        $this->applyUsage($this->storeKey($store), $storeUsage);

        $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT);
        if ($retryAfter !== false && $retryAfter > 0) {
            $this->block($this->storeKey($store), min(300, $retryAfter));
        }

        $metaCode = filter_var($response->json('error.code'), FILTER_VALIDATE_INT);
        if ($response->status() === 429 || in_array($metaCode, self::RATE_LIMIT_ERROR_CODES, true)) {
            $this->block(
                $this->storeKey($store),
                max(1, (int) config('services.meta_ads.rate_limit_pause_seconds', 60)),
            );
        }
    }

    private function applyUsage(string $key, float $usage): void
    {
        $high = max(1, (int) config('services.meta_ads.usage_high_threshold', 90));
        $medium = min($high, max(1, (int) config('services.meta_ads.usage_medium_threshold', 75)));

        if ($usage >= $high) {
            $this->block($key, max(1, (int) config('services.meta_ads.usage_high_pause_seconds', 30)));
        } elseif ($usage >= $medium) {
            $this->block($key, max(1, (int) config('services.meta_ads.usage_medium_pause_seconds', 10)));
        }
    }

    private function block(string $key, int $seconds): void
    {
        $blockedUntil = time() + $seconds;
        $current = (int) $this->cache->get($key, 0);

        if ($blockedUntil > $current) {
            $this->cache->put($key, $blockedUntil, $seconds + 60);
        }
    }

    private function usage(?string $header): float
    {
        if (! is_string($header) || trim($header) === '') {
            return 0;
        }

        $decoded = json_decode($header, true);

        return is_array($decoded) ? $this->maximumNumericValue($decoded) : 0;
    }

    /** @param array<array-key, mixed> $values */
    private function maximumNumericValue(array $values): float
    {
        $maximum = 0;

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $maximum = max($maximum, $this->maximumNumericValue($value));
            } elseif (is_numeric($value) && in_array((string) $key, [
                'call_count',
                'total_cputime',
                'total_time',
                'acc_id_util_pct',
            ], true)) {
                $maximum = max($maximum, (float) $value);
            }
        }

        return $maximum;
    }

    private function appKey(): string
    {
        return 'meta-ads:usage:app:blocked-until';
    }

    private function storeKey(Store $store): string
    {
        return "meta-ads:usage:{$store->organization_id}:{$store->getKey()}:blocked-until";
    }
}
