<?php

namespace App\Services\SeoAnalytics;

use Illuminate\Support\Facades\Cache;

class SeoAnalyticsCacheVersionService
{
    public function current(int $storeId): int
    {
        return (int) Cache::get($this->key($storeId), 1);
    }

    public function bump(int $storeId): int
    {
        $key = $this->key($storeId);

        if (! Cache::has($key)) {
            Cache::forever($key, 1);
        }

        return (int) Cache::increment($key);
    }

    private function key(int $storeId): string
    {
        return "seo-analytics:store:{$storeId}:version";
    }
}
