<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\AuditLog;
use App\Models\InstagramFeedInstallation;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * DecoAdmin -> Shopify 方向的连接探活。
 *
 * 商家侧不需要任何授权动作（安装由 Shopify 托管、会话由 token exchange 建立），
 * 所以后台不再提供“人工授权”入口；后台要回答的问题只有一个：这条连接现在还好吗。
 *
 * 状态分流与主 App 的 ShopifyConnectionHealthService 保持一致：
 * 401/403 视为凭证失效（invalid），其它失败视为需要关注（warning），成功则 connected。
 * 这样「系统状态」页面对四个 App 的判读口径是同一套。
 */
class InstagramFeedConnectionHealthService
{
    private const INSTALLATION_QUERY = 'query InstagramFeedInstallationProbe { currentAppInstallation { id } }';

    public function __construct(private InstagramFeedShopifyClient $client) {}

    /**
     * 探活单个店铺并把结果写回安装记录。
     *
     * @return array{status: string, message: string}
     */
    public function check(Store $store, ?User $actor = null): array
    {
        $installation = InstagramFeedInstallation::query()->where('store_id', $store->id)->first();
        if (! $installation || ! $installation->isUsable()) {
            return [
                'status' => InstagramFeedInstallation::STATUS_DISCONNECTED,
                'message' => '该店铺尚未在 Shopify 后台打开过 Instagram 内容应用，会话还未建立。',
            ];
        }

        try {
            $payload = $this->client->graphqlWithToken(
                $store->shopify_domain,
                (string) $installation->access_token_encrypted,
                self::INSTALLATION_QUERY,
            );
        } catch (InstagramFeedException $exception) {
            $status = $exception->statusCode === 401
                ? InstagramFeedInstallation::STATUS_INVALID
                : InstagramFeedInstallation::STATUS_WARNING;
            $installation->fill([
                'status' => $status,
                'last_api_check' => now(),
                'last_error' => $this->safeReason($exception->getMessage()),
                'last_error_at' => now(),
            ])->save();

            return ['status' => $status, 'message' => $exception->getMessage()];
        }

        $installationId = data_get($payload, 'data.currentAppInstallation.id');
        if (! is_string($installationId) || $installationId === '') {
            // 能连上却查不到安装记录，说明应用已经从这家店卸载了。
            $installation->fill([
                'status' => InstagramFeedInstallation::STATUS_DISCONNECTED,
                'last_api_check' => now(),
                'last_error' => '未找到该店铺上的应用安装记录，应用可能已被卸载。',
                'last_error_at' => now(),
            ])->save();

            return [
                'status' => InstagramFeedInstallation::STATUS_DISCONNECTED,
                'message' => '未找到该店铺上的应用安装记录，应用可能已被卸载。',
            ];
        }

        $installation->fill([
            'status' => InstagramFeedInstallation::STATUS_CONNECTED,
            'app_installation_id' => $installationId,
            'last_verified_at' => now(),
            'last_api_check' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor?->id,
            'action' => 'instagram_feed_connection_checked',
            'subject_type' => Store::class,
            'subject_id' => $store->id,
            'metadata' => [
                'scope' => 'store',
                'environment' => (string) config('instagram_feed.environment'),
                'actor_type' => $actor ? 'user' : 'system',
                'shop_domain' => $store->shopify_domain,
                'app_installation_id' => $installationId,
            ],
        ]);

        return [
            'status' => InstagramFeedInstallation::STATUS_CONNECTED,
            'message' => 'Instagram 内容应用的 Shopify 连接正常。',
        ];
    }

    /** 错误原因要落库并展示给运营，先抹掉可能夹带的令牌再截断。 */
    private function safeReason(string $reason): string
    {
        $redacted = preg_replace('/\b(shp(at|ca|pa|ss)_[A-Za-z0-9]+)\b/', '[redacted]', $reason) ?? $reason;

        return Str::limit(trim($redacted), 1000, '');
    }
}
