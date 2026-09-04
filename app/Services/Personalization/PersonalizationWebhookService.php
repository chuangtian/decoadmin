<?php

namespace App\Services\Personalization;

use App\Exceptions\PersonalizationException;
use App\Models\App;
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
    private const ALLOWED_TOPICS = [
        'app/uninstalled',
        'app/scopes_update',
        'customers/data_request',
        'customers/redact',
        'shop/redact',
    ];

    private const PRIVACY_TOPICS = ['customers/data_request', 'customers/redact', 'shop/redact'];

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
        $store = $this->storeForHeader($shopDomain, str_starts_with($topic, 'app/'));
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

            if (str_starts_with($topic, 'app/')) {
                if (! $connection) {
                    throw new PersonalizationException('STORE_NOT_CONNECTED', 'Webhook 店铺尚未连接 DecoAdmin Commerce Hub。', 404);
                }
                $installation = $this->registry->synchronizeInstallation(
                    $store,
                    $connection,
                    $topic === 'app/uninstalled' ? 'uninstalled' : 'active',
                    $scopes,
                    'personalization_webhook',
                );
                $app = $installation->app;
            } else {
                $app = App::query()
                    ->where('handle', (string) config('personalization.active.handle'))
                    ->where('status', 'active')
                    ->first();
                if (! $app) {
                    throw new PersonalizationException(
                        'PERSONALIZATION_APP_NOT_CONFIGURED',
                        '个性化推荐 App 的 Test 环境尚未配置。',
                        503,
                    );
                }
                $installation = AppInstallation::withTrashed()
                    ->where('app_id', $app->id)
                    ->where('store_id', $store->id)
                    ->first();
            }
            $eventSource = PersonalizationEventSource::query()
                ->where('organization_id', $store->organization_id)
                ->where('store_id', $store->id)
                ->first();
            if ($eventSource && ! in_array($topic, ['customers/data_request', 'customers/redact'], true)) {
                $pixelScopesGranted = in_array('write_pixels', $scopes, true)
                    && in_array('read_customer_events', $scopes, true);
                $active = $topic === 'app/scopes_update'
                    && $pixelScopesGranted
                    && filled($eventSource->web_pixel_id);
                $purgeAfter = match ($topic) {
                    'app/uninstalled' => now()->addHours((int) config('personalization.retention.uninstall_purge_hours', 48)),
                    'shop/redact' => now(),
                    default => $active ? null : $eventSource->purge_after,
                };
                $eventSource->forceFill([
                    'status' => $active ? 'active' : 'inactive',
                    'activated_at' => $active ? ($eventSource->activated_at ?? now()) : null,
                    'purge_after' => $purgeAfter,
                ])->save();
            }
            $receivedAt = now();
            $privacyTopic = in_array($topic, self::PRIVACY_TOPICS, true);
            $event = WebhookEvent::query()->firstOrCreate(
                ['webhook_id' => $webhookId],
                [
                    'organization_id' => $store->organization_id,
                    'store_id' => $store->id,
                    'shopify_connection_id' => $connection?->id,
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
                    'payload' => ['storage' => $privacyTopic ? 'redacted' : 'encrypted'],
                    'payload_encrypted' => $privacyTopic ? null : $rawPayload,
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
                'action' => match ($topic) {
                    'app/uninstalled' => 'personalization_shopify_app_uninstalled',
                    'app/scopes_update' => 'personalization_shopify_app_scopes_updated',
                    'customers/data_request' => 'personalization_customer_data_request_received',
                    'customers/redact' => 'personalization_customer_redact_received',
                    default => 'personalization_shop_redact_received',
                },
                'subject_type' => $installation ? AppInstallation::class : Store::class,
                'subject_id' => $installation?->id ?? $store->id,
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
                'processing_result' => match ($topic) {
                    'customers/data_request', 'customers/redact' => 'no_customer_data',
                    'app/uninstalled', 'shop/redact' => 'purge_scheduled',
                    default => 'handled',
                },
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

    private function storeForHeader(string $shopDomain, bool $requireConnection): Store
    {
        $store = Store::query()
            ->where('shopify_domain', $shopDomain)
            ->where('status', 'active')
            ->whereHas('organization', fn ($query) => $query->where('status', 'active'))
            ->when($requireConnection, fn ($query) => $query->whereHas(
                'shopifyConnection',
                fn ($connection) => $connection->whereIn('status', ['connected', 'warning']),
            ))
            ->with([
                'organization',
                'shopifyConnection',
            ])
            ->first();
        if (! $store || ! $store->organization || ($requireConnection && ! $store->shopifyConnection)) {
            throw new PersonalizationException(
                'STORE_NOT_CONNECTED',
                'Webhook 店铺尚未连接 DecoAdmin Commerce Hub。',
                404,
            );
        }

        return $store;
    }
}
