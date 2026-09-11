<?php

namespace CommunityReviews\Services;

use App\Models\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class FeedCache
{
    public function prefix(int $organization, int $store): string
    {
        return 'community-reviews:v2:'.config('community_reviews.environment').':'.$organization.':'.$store;
    }

    public function invalidate(int $organization, int $store): void
    {
        // A generation prevents an in-flight refresh from restoring invalidated data.
        Cache::forever($this->prefix($organization, $store).':generation', (string) Str::uuid());
    }

    public function remember(Store $store, string $part, int $seconds, callable $load): array
    {
        $prefix = $this->prefix($store->organization_id, $store->id);
        $generation = Cache::get($prefix.':generation', 'initial');
        $key = $prefix.':'.$generation.':'.$part;
        $cached = Cache::get($key);
        if (is_array($cached)) return $cached;

        return Cache::lock($key.':lock', 30)->block(3, function () use ($key, $seconds, $load) {
            $cached = Cache::get($key);
            if (is_array($cached)) return $cached;
            $value = $load();
            Cache::put($key, $value, $seconds);
            return $value;
        });
    }

    public function select(Store $store, ?string $visitor, callable $select): array
    {
        if (! $visitor || ! Str::isUuid($visitor)) return $select([], [])['feed'];
        $key = $this->prefix($store->organization_id, $store->id).':visitor:'.hash('sha256', $visitor);
        return Cache::lock($key.':lock', 15)->block(3, function () use ($key, $select) {
            $history = Cache::get($key, ['reviews' => [], 'images' => []]);
            $result = $select($history['reviews'], $history['images']);
            foreach (['reviews', 'images'] as $kind) {
                $history[$kind] = array_slice(array_values(array_unique([
                    ...array_diff($history[$kind], $result[$kind]), ...$result[$kind],
                ])), -2000);
            }
            // Anonymous, store-scoped display history; no account, IP or user-agent data.
            Cache::put($key, $history, 86400);
            return $result['feed'];
        });
    }
}
