<?php

namespace App\Services\Advertising;

use App\Exceptions\AdvertisingApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

class AdvertisingSyncFailurePolicy
{
    /** Safe, structured diagnostics: never persist response bodies or exception text. */
    public static function describe(Throwable $exception): array
    {
        $status = $exception instanceof AdvertisingApiException ? $exception->httpStatus : null;
        $code = match (true) {
            $status === 429 => 'advertising_rate_limited',
            $status === 504 || $status === 408 => 'advertising_timeout',
            in_array($status, [401, 403], true) => 'advertising_authorization_failed',
            $status !== null && $status >= 500 => 'advertising_upstream_unavailable',
            $exception instanceof ConnectionException => 'advertising_network_error',
            default => 'advertising_channel_sync_failed',
        };

        return ['code' => $code, 'http_status' => $status, 'transient' => self::isTransient($code),
            'reason' => match ($code) {
                'advertising_rate_limited' => '接口限流，请求暂时被拒绝',
                'advertising_timeout' => '报表接口超时',
                'advertising_authorization_failed' => '接口授权或权限校验失败，请检查该广告渠道授权',
                'advertising_upstream_unavailable' => '广告平台接口暂时不可用',
                'advertising_network_error' => '连接广告平台失败或网络超时',
                default => '同步失败，需检查该广告渠道的运行记录',
            }];
    }

    public static function isTransient(?string $code): bool
    {
        return in_array($code, ['advertising_rate_limited', 'advertising_timeout', 'advertising_upstream_unavailable', 'advertising_network_error'], true);
    }

    public static function delay(int $failures, Throwable $exception): int
    {
        $delay = min(21600, 1800 * (2 ** min(4, max(0, $failures - 1))));

        return max($delay, $exception instanceof AdvertisingApiException ? $exception->retryAfterSeconds : 0);
    }

    public static function retryAfter(?string $value): int
    {
        if ($value === null || trim($value) === '') {
            return 0;
        }
        if (ctype_digit(trim($value))) {
            return (int) min(604800, (float) trim($value));
        }
        try {
            return (int) min(604800, max(0, now()->diffInSeconds(CarbonImmutable::parse($value), false)));
        } catch (Throwable) {
            return 0;
        }
    }
}
