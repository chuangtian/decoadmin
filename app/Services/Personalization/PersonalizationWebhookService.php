<?php

namespace App\Services\Personalization;

use App\Exceptions\PersonalizationException;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\PersonalizationEventSource;
use App\Models\Store;
use App\Models\WebhookEvent;
use App\Services\Shopify\ShopifyWebhookHmacValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class PersonalizationWebhookService
{
    private const ALLOWED_TOPICS = ['app/uninstalled', 'app/scopes_update'];

    public function __construct(
        private ShopifyWebhookHmacValidator $hmacValidator,
        private PersonalizationAppRegistryService $registry,
        private PersonalizationShopGuard $shopGuard,
    ) {}

    /**
     * @param  array<string, string|null>  $headers
     * @return array{event: WebhookEvent, created: bool}
     */
    public function receive(string $rawPayload, array $headers): array
    {
        if (strlen($rawPayload) > 10 * 1024 * 1024) {
            throw new PersonalizationException('WEBHOOK_PAYLOAD_TOO_LARGE', 'Webhook 载荷超过允许大小。', 413);
        }

        $secret = (string) config('personalization.active.client_secret');
        if ((string) config('personalization.environment') !== 'test' || $secret === '') {
            throw new PersonalizationException(
                'PERSONALIZATION_APP_NOT_CONFIGURED',
                '个性化推荐 App 的 Test 环境尚未配置。',
                503,
            );
        }
        if (! $this->hmacValidator->validate($rawPayload, $headers['hmac'] ?? null, $secret)) {
            throw new PersonalizationException('INVALID_WEBHOOK_HMAC', 'Webhook 签名验证失败。', 401);
        }

        $webhookId = trim((string) ($headers['webhook_id'] ?? ''));
        $topic = trim((string) ($headers['topic'] ?? ''));
        $shopDomain = $this->shopGuard->assertAllowed((string) ($headers['shop_domain'] ?? ''));
        $apiVersion = trim((string) ($headers['api_version'] ?? ''));
        if (! Str::isUuid($webhookId) || mb_strlen($apiVersion) > 20) {
            throw new PersonalizationException(
                'INVALID_WEBHOOK_HEADERS',
                'Webhook 请求头缺少有效的事件标识或店铺域名。',
            );
        }
        if (! in_array($topic, self::ALLOWED_TOPICS, true)) {
            throw new PersonalizationException(
                'UNSUPPORTED_WEBHOOK_TOPIC',
                '个性化推荐 App 不处理该 Webhook 主题。',
            );
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PersonalizationException('INVALID_WEBHOOK_PAYLOAD', 'Webhook 载荷不是有效 JSON。');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new PersonalizationException('INVALID_WEBHOOK_PAYLOAD', 'Webhook 载荷格式无效。');
        }

        $scopes = $topic === 'app/scopes_update' ? $this->validatedScopes($payload) : [];
        $store = $this->storeForHeader($shopDomain);
        $connection = $store->shopifyConnection;
        $payloadHash = hash('sha256', $rawPayload);

        return DB::transaction(function () use ($store, $connection, $webhookId, $topic, $shopDomain, $apiVersion, $headers, $rawPayload, $payloadHash, $scopes): array {
            $existingEvent = WebhookEvent::query()
                ->where('webhook_id', $webhookId)
                ->lockForUpdate()
                ->first();
            if ($existingEvent) {
                $expectedHandle = trim((string) config('personalization.active.handle'));
                $existingAppHandle = $existingEvent->app?->handle;
                if (! is_string($existingEvent->payload_sha256)
                    || ! hash_equals($existingEvent->payload_sha256, $payloadHash)
                    || ! is_string($existingAppHandle)
                    || ! hash_equals($expectedHandle, $existingAppHandle)) {
                    throw new PersonalizationException(
                        'WEBHOOK_ID_CONFLICT',
                        'Webhook 事件 ID 与已保存事件冲突。',
                        409,
                    );
                }

                return ['event' => $existingEvent, 'created' => false];
            }

            $installation = $this->registry->synchronizeInstallation(
                $store,
                $connection,
                $topic === 'app/uninstalled' ? 'uninstalled' : 'active',
                $scopes,
                'personalization_webhook',
            );
            $eventSource = PersonalizationEventSource::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->first();
            if ($eventSource) {
                $pixelScopesGranted = in_array('write_pixels', $scopes, true)
                    && in_array('read_customer_events', $scopes, true);
                $active = $topic === 'app/scopes_update'
                    && $pixelScopesGranted
                    && filled($eventSource->web_pixel_id);
                $eventSource->forceFill([
                    'status' => $active ? 'active' : 'inactive',
                    'activated_at' => $active ? ($eventSource->activated_at ?? now()) : null,
                ])->save();
            }
            $app = $installation->app;
            $receivedAt = now();
            $event = WebhookEvent::query()->firstOrCreate(
                ['webhook_id' => $webhookId],
                [
                    'organization_id' => $store->organization_id,
                    'store_id' => $store->id,
                    'shopify_connection_id' => $connection->id,
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
                    throw new PersonalizationException(
                        'WEBHOOK_ID_CONFLICT',
                        'Webhook 事件 ID 与已保存事件冲突。',
                        409,
                    );
                }

                return ['event' => $event, 'created' => false];
            }

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'action' => $topic === 'app/uninstalled'
                    ? 'personalization_shopify_app_uninstalled'
                    : 'personalization_shopify_app_scopes_updated',
                'subject_type' => AppInstallation::class,
                'subject_id' => $installation->id,
                'metadata' => [
                    'scope' => 'store',
                    'environment' => (string) config('personalization.environment'),
                    'webhook_id' => $webhookId,
                    'topic' => $topic,
                    'granted_scopes' => $topic === 'app/scopes_update' ? $scopes : [],
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

    /** @param array<string, mixed> $payload @return list<string> */
    private function validatedScopes(array $payload): array
    {
        $current = $payload['current'] ?? null;
        if (! is_array($current) || count($current) > 100) {
            throw new PersonalizationException(
                'INVALID_SCOPE_UPDATE_PAYLOAD',
                '权限更新 Webhook 缺少有效的 current 列表。',
            );
        }

        $scopes = [];
        foreach ($current as $scope) {
            if (! is_string($scope) || ! preg_match('/^[a-z][a-z0-9_]{0,99}$/', $scope)) {
                throw new PersonalizationException(
                    'INVALID_SCOPE_UPDATE_PAYLOAD',
                    '权限更新 Webhook 包含无效权限。',
                );
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
            ->whereHas('shopifyConnection', fn ($query) => $query->whereIn('status', ['connected', 'warning']))
            ->with([
                'organization',
                'shopifyConnection' => fn ($query) => $query->whereIn('status', ['connected', 'warning']),
            ])
            ->first();
        if (! $store || ! $store->organization || ! $store->shopifyConnection) {
            throw new PersonalizationException(
                'STORE_NOT_CONNECTED',
                'Webhook 店铺尚未连接 DecoAdmin Commerce Hub。',
                404,
            );
        }

        return $store;
    }
}
