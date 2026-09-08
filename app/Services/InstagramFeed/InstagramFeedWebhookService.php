<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\InstagramFeedInstallation;
use App\Models\Store;
use App\Models\WebhookEvent;
use App\Services\Shopify\ShopifyWebhookHmacValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/**
 * instagram-feed App 自己的 Shopify Webhook 入口。
 *
 * 只处理这个 App 的生命周期主题，且用它自己的 client secret 验签 —— 与 DecoAdmin
 * 主 App 的 webhook 通道完全隔离。
 */
class InstagramFeedWebhookService
{
    private const ALLOWED_TOPICS = ['app/uninstalled', 'app/scopes_update'];

    private const MAX_PAYLOAD_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private ShopifyWebhookHmacValidator $hmacValidator,
        private InstagramFeedAppRegistry $registry,
        private InstagramMirrorService $mirror,
    ) {}

    /**
     * @param  array<string, string|null>  $headers
     * @return array{event: WebhookEvent, created: bool}
     */
    public function receive(string $rawPayload, array $headers): array
    {
        if (strlen($rawPayload) > self::MAX_PAYLOAD_BYTES) {
            throw new InstagramFeedException('WEBHOOK_PAYLOAD_TOO_LARGE', 'Webhook 载荷超过允许大小。', 413);
        }

        $secret = $this->registry->clientSecret();
        if (! $this->hmacValidator->validate($rawPayload, $headers['hmac'] ?? null, $secret)) {
            throw new InstagramFeedException('INVALID_WEBHOOK_HMAC', 'Webhook 签名验证失败。', 401);
        }

        $webhookId = trim((string) ($headers['webhook_id'] ?? ''));
        $topic = trim((string) ($headers['topic'] ?? ''));
        $shopDomain = strtolower(trim((string) ($headers['shop_domain'] ?? '')));
        $apiVersion = trim((string) ($headers['api_version'] ?? ''));
        if (! Str::isUuid($webhookId)
            || preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shopDomain) !== 1
            || mb_strlen($apiVersion) > 20) {
            throw new InstagramFeedException(
                'INVALID_WEBHOOK_HEADERS',
                'Webhook 请求头缺少有效的事件标识或店铺域名。',
            );
        }
        if (! in_array($topic, self::ALLOWED_TOPICS, true)) {
            throw new InstagramFeedException('UNSUPPORTED_WEBHOOK_TOPIC', 'Instagram Feed App 不处理该 Webhook 主题。');
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InstagramFeedException('INVALID_WEBHOOK_PAYLOAD', 'Webhook 载荷不是有效 JSON。');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new InstagramFeedException('INVALID_WEBHOOK_PAYLOAD', 'Webhook 载荷格式无效。');
        }

        $scopes = $topic === 'app/scopes_update' ? $this->validatedScopes($payload) : [];
        $store = $this->storeForHeader($shopDomain);
        $payloadHash = hash('sha256', $rawPayload);

        return DB::transaction(function () use ($store, $webhookId, $topic, $shopDomain, $apiVersion, $headers, $rawPayload, $payloadHash, $scopes): array {
            $app = $this->registry->configuredApp();
            $receivedAt = now();
            $event = WebhookEvent::query()->firstOrCreate(
                ['webhook_id' => $webhookId],
                [
                    'organization_id' => $store->organization_id,
                    'store_id' => $store->id,
                    'shopify_connection_id' => $store->shopifyConnection?->id,
                    'app_id' => $app->id,
                    'topic' => $topic,
                    'api_version' => $apiVersion ?: null,
                    'headers' => array_filter([
                        'webhook_id' => $webhookId,
                        'topic' => $topic,
                        'shop_domain' => $shopDomain,
                        'api_version' => $apiVersion ?: null,
                        'triggered_at' => mb_substr(trim((string) ($headers['triggered_at'] ?? '')), 0, 64) ?: null,
                    ], fn (mixed $value): bool => $value !== null && $value !== ''),
                    'payload' => ['storage' => 'encrypted'],
                    'payload_encrypted' => $rawPayload,
                    'payload_sha256' => $payloadHash,
                    'status' => 'processing',
                    'attempts' => 1,
                    'received_at' => $receivedAt,
                    'processing_started_at' => $receivedAt,
                ],
            );

            if (! $event->wasRecentlyCreated) {
                if ((int) $event->app_id !== (int) $app->id
                    || ! is_string($event->payload_sha256)
                    || ! hash_equals($event->payload_sha256, $payloadHash)) {
                    throw new InstagramFeedException('WEBHOOK_ID_CONFLICT', 'Webhook 事件 ID 与已保存事件冲突。', 409);
                }

                return ['event' => $event, 'created' => false];
            }

            // 与 bootstrap 共用同一个写入口，保证应用中心看到的状态和权限范围一致。
            $installation = $this->registry->synchronizeInstallation(
                $store,
                $topic === 'app/uninstalled' ? 'uninstalled' : 'active',
                $scopes,
                'instagram_feed_webhook',
            );

            $purged = ['records' => 0, 'objects' => 0, 'failed_keys' => []];
            if ($topic === 'app/uninstalled') {
                $purged = $this->handleUninstall($store);
            } else {
                InstagramFeedInstallation::query()
                    ->where('store_id', $store->id)
                    ->update(['granted_scopes' => $scopes, 'last_verified_at' => $receivedAt]);
            }

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'action' => $topic === 'app/uninstalled'
                    ? 'instagram_feed_shopify_app_uninstalled'
                    : 'instagram_feed_shopify_app_scopes_updated',
                'subject_type' => AppInstallation::class,
                'subject_id' => $installation->id,
                'metadata' => [
                    'scope' => 'store',
                    'environment' => $this->registry->environment(),
                    'webhook_id' => $webhookId,
                    'topic' => $topic,
                    'granted_scopes' => $topic === 'app/scopes_update' ? $scopes : [],
                    'purged_records' => $purged['records'],
                    'purged_objects' => $purged['objects'],
                ],
            ]);

            $event->forceFill([
                'status' => 'processed',
                'processing_result' => 'handled',
                'handler' => self::class,
                'processed_at' => now(),
                'processing_duration_ms' => 0,
            ])->save();

            return ['event' => $event, 'created' => true];
        });
    }

    /**
     * 卸载后清掉授权、转存产物和 App 会话，避免残留过期 token 和一直计费的 R2 对象。
     *
     * 媒体多的时候这里可能超过 webhook 的响应预算，被 Shopify 判超时后重投。
     * purgeStoreMedia 先删对象再删记录，所以重投能接着上次的进度继续删，
     * 全删完之后再重投也只是空跑一次。
     *
     * @return array{records: int, objects: int, failed_keys: list<string>}
     */
    private function handleUninstall(Store $store): array
    {
        $purged = $this->mirror->purgeStoreMedia($store);
        $store->instagramGalleries()->delete();
        $store->instagramAccount()->delete();
        // 记录保留、只清凭证并标记卸载：后台的「安装店铺」列表要能看到卸载历史，
        // 硬删会让这条记录连同授权时间一起消失。
        InstagramFeedInstallation::query()->where('store_id', $store->id)->update([
            'status' => InstagramFeedInstallation::STATUS_DISCONNECTED,
            'app_installation_id' => null,
            'access_token_encrypted' => null,
            'uninstalled_at' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ]);

        return $purged;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function validatedScopes(array $payload): array
    {
        $current = $payload['current'] ?? null;
        if (! is_array($current) || count($current) > 100) {
            throw new InstagramFeedException(
                'INVALID_SCOPE_UPDATE_PAYLOAD',
                '权限更新 Webhook 缺少有效的 current 列表。',
            );
        }

        $scopes = [];
        foreach ($current as $scope) {
            if (! is_string($scope) || preg_match('/^[a-z][a-z0-9_]{0,99}$/', $scope) !== 1) {
                throw new InstagramFeedException('INVALID_SCOPE_UPDATE_PAYLOAD', '权限更新 Webhook 包含无效权限。');
            }
            $scopes[] = $scope;
        }
        sort($scopes);

        return array_values(array_unique($scopes));
    }

    private function storeForHeader(string $shopDomain): Store
    {
        $store = Store::query()
            ->where('shopify_domain', $shopDomain)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->with(['organization', 'shopifyConnection'])
            ->first();
        if (! $store || ! $store->organization) {
            throw new InstagramFeedException('STORE_NOT_CONNECTED', 'Webhook 店铺尚未连接 DecoAdmin。', 404);
        }

        return $store;
    }
}
