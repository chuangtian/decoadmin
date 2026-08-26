<?php

namespace App\Services\InstagramFeed;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * 两条授权路线返回的媒体结构一致，统一映射成同一套内部字段，
 * 上层同步逻辑不用关心数据是从哪条路线拉来的。
 */
class MetaMediaMapper
{
    /**
     * @param  array<string, mixed>  $raw
     * @return array{ig_media_id: string, media_type: string, media_product_type: string|null, caption: string|null, permalink: string, media_url: string|null, thumbnail_url: string|null, posted_at: CarbonImmutable|null, like_count: int|null, comments_count: int|null}
     */
    public static function map(array $raw): array
    {
        return [
            'ig_media_id' => (string) ($raw['id'] ?? ''),
            'media_type' => self::text($raw['media_type'] ?? null, 24) ?? 'IMAGE',
            'media_product_type' => self::text($raw['media_product_type'] ?? null, 24),
            'caption' => self::text($raw['caption'] ?? null, 65000),
            'permalink' => self::text($raw['permalink'] ?? null, 1024) ?? '',
            'media_url' => self::text($raw['media_url'] ?? null, 65000),
            'thumbnail_url' => self::text($raw['thumbnail_url'] ?? null, 65000),
            'posted_at' => self::timestamp($raw['timestamp'] ?? null),
            'like_count' => self::count($raw['like_count'] ?? null),
            'comments_count' => self::count($raw['comments_count'] ?? null),
        ];
    }

    private static function text(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }

    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function count(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }
}
