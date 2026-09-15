<?php

namespace App\Services\Advertising;

use App\Models\Store;
use Carbon\CarbonImmutable;

class GoogleAdsDateRangeService
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable} */
    public function resolve(Store $store, array $filters): array
    {
        $timezone = $store->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $to = $this->date($filters['date_to'] ?? null, $today, $timezone)->min($today);
        $from = $this->date($filters['date_from'] ?? null, $to->subDays(6), $timezone)->min($today);
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > 365) {
            $from = $to->subDays(365);
        }

        return [$from, $to, $today];
    }

    private function date(mixed $value, CarbonImmutable $fallback, string $timezone): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $fallback;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            return $date && $date->toDateString() === $value ? $date : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
