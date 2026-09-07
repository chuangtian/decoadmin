<?php

namespace App\Support;

use App\Models\Store;
use Carbon\CarbonImmutable;
use DateTimeZone;

final class StoreDateTime
{
    public static function timezone(Store $store): string
    {
        $timezone = (string) $store->timezone;

        return in_array($timezone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true) ? $timezone : 'UTC';
    }

    public static function format(mixed $value, Store $store): string
    {
        if (blank($value)) {
            return '无';
        }

        $timezone = self::timezone($store);
        $date = CarbonImmutable::parse($value, 'UTC')->setTimezone($timezone);

        return $date->format('Y-m-d H:i:s').' ('.$timezone.', UTC'.$date->format('P').')';
    }
}
