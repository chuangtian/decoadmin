<?php

namespace App\Services\Feishu;

use App\Models\Store;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MfDailyReportPublisher
{
    public function __construct(private FeishuBitableClient $client) {}

    public function publish(Store $store, string $text, string $imageContents): void
    {
        $store->loadMissing('notificationSetting');
        $settings = $store->notificationSetting;

        if (! $settings || ! $settings->feishu_enabled || ! filled($settings->feishu_webhook_url)) {
            throw new RuntimeException('当前店铺未启用飞书机器人通知。');
        }

        $imageKey = $this->client->uploadMessageImage($imageContents);
        $content = collect(explode("\n", trim($text)))
            ->map(fn (string $line): array => [[
                'tag' => 'text',
                'text' => $line === '' ? ' ' : $line,
            ]])
            ->values()
            ->all();
        $content[] = [['tag' => 'img', 'image_key' => $imageKey]];

        $payload = [
            'msg_type' => 'post',
            'content' => [
                'post' => [
                    'zh_cn' => [
                        'title' => '',
                        'content' => $content,
                    ],
                ],
            ],
        ];

        if (filled($settings->feishu_secret)) {
            $timestamp = (string) now()->timestamp;
            $payload['timestamp'] = $timestamp;
            $payload['sign'] = base64_encode(hash_hmac(
                'sha256',
                '',
                $timestamp."\n".$settings->feishu_secret,
                true,
            ));
        }

        $response = Http::asJson()
            ->timeout(15)
            ->retry(3, 300, throw: false)
            ->post($settings->feishu_webhook_url, $payload);
        $responseCode = $response->json('code');
        if ($responseCode === null) {
            $responseCode = $response->json('StatusCode');
        }

        if (! $response->successful() || (int) ($responseCode ?? -1) !== 0) {
            $responseMessage = trim((string) (
                $response->json('msg')
                ?? $response->json('StatusMessage')
                ?? '未知错误'
            ));
            $responseMessage = mb_substr(preg_replace(
                '/https?:\/\/[^\s]+|(?:app|tbl|vew|img)[A-Za-z0-9_-]{6,}/i',
                '[redacted]',
                $responseMessage,
            ) ?: '未知错误', 0, 200);

            throw new RuntimeException(sprintf(
                '飞书机器人返回失败状态（HTTP %d，错误码 %s）：%s',
                $response->status(),
                is_scalar($responseCode) ? (string) $responseCode : 'unknown',
                $responseMessage,
            ));
        }
    }
}
