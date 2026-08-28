<?php

namespace App\Services\SeoAnalytics;

class GscDetailRetentionPolicy
{
    public const MIN_IMPRESSIONS = 5;

    public function shouldStore(int $clicks, int $impressions, int $minimumImpressions = self::MIN_IMPRESSIONS): bool
    {
        return $clicks > 0 || $impressions >= max(1, $minimumImpressions);
    }
}
