<?php

namespace App\Services\StudentDiscount;

use App\Exceptions\StudentDiscountException;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\WebhookEvent;
use App\Services\Shopify\ShopifyWebhookHmacValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class StudentDiscountWebhookService
{
    private const ALLOWED_TOPICS = ['app/uninstalled', 'app/scopes_update'];

    public function __construct(private ShopifyWebhookHmacValidator $hmacValidator) {}

    /**
     * @param  array<string, string|null>  $headers
     * @return array{event: WebhookEvent, created: bool}
     */
    public function receive(string $rawPayload, array $headers): array
    {
        if (strlen($rawPayload) > 10 * 1024 * 1024) {
            throw new StudentDiscountException('WEBHOOK_PAYLOAD_TOO_LARGE', 'Webhook 载荷超过允许大小。', 413);
        }

        $secret = (string) config('student_discount.active.client_secret');
        if ($secret === '') {
            throw new StudentDiscountException('STUDENT_DISCOUNT_APP_NOT_CONFIGURED', '学生优惠 App 当前环境尚未配置。', 503);
        }
        if (! $this->hmacValidator->validate($rawPayload, $headers['hmac'] ?? null, $secret)) {
            throw new StudentDiscountException('INVALID_WEBHOOK_HMAC', 'Webhook 签名验证失败。', 401);
        }

        $webhookId = trim((string) ($headers['webhook_id'] ?? ''));
        $topic = trim((string) ($headers['topic'] ?? ''));
        $shopDomain = strtolower(trim((string) ($headers['shop_domain'] ?? '')));
        $apiVersion = trim((string) ($headers['api_version'] ?? ''));
        if (! Str::isUuid($webhookId)
            || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shopDomain)
            || mb_strlen($apiVersion) > 20) {
            throw new StudentDiscountException('INVALID_WEBHOOK_HEADERS', 'Webhook 请求头缺少有效的事件标识或店铺域名。');
        }
        if (! in_array($topic, self::ALLOWED_TOPICS, true)) {
            throw new StudentDiscountException('UNSUPPORTED_WEBHOOK_TOPIC', '学生优惠 App 不处理该 Webhook 主题。');
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new StudentDiscountException('INVALID_WEBHOOK_PAYLOAD', 'Webhook 载荷不是有效 JSON。');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new StudentDiscountException('INVALID_WEBHOOK_PAYLOAD', 'Webhook 载荷格式无效。');
        }

        $scopes = $topic === 'app/scopes_update' ? $this->validatedScopes($payload) : [];
        $store = $this->storeForHeader($shopDomain);
        $connection = $store->shopifyConnection;
        $payloadHash = hash('sha256', $rawPayload);

        return DB::transaction(function () use ($store, $connection, $webhookId, $topic, $shopDomain, $apiVersion, $headers, $rawPayload, $payloadHash, $scopes): array {
            $app = $this->configuredApp();
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
                    throw new StudentDiscountException('WEBHOOK_ID_CONFLICT', 'Webhook 事件 ID 与已保存事件冲突。', 409);
                }

                return ['event' => $event, 'created' => false];
            }

            $installation = AppInstallation::withTrashed()
                ->where('app_id', $app->id)
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first() ?? new AppInstallation;
            $installation->fill([
                'app_id' => $app->id,
                'store_id' => $store->id,
                'shopify_connection_id' => $connection->id,
                'status' => $topic === 'app/uninstalled' ? 'uninstalled' : 'active',
                'granted_scopes' => $topic === 'app/scopes_update'
                    ? $scopes
                    : (is_array($installation->granted_scopes) ? $installation->granted_scopes : []),
                'settings' => [
                    'source' => 'student_discount_webhook',
                    'environment' => (string) config('student_discount.environment'),
                ],
                'installed_at' => $installation->installed_at ?? $receivedAt,
                'uninstalled_at' => $topic === 'app/uninstalled' ? $receivedAt : null,
            ]);
            $installation->deleted_at = null;
            $installation->save();

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'action' => $topic === 'app/uninstalled'
                    ? 'student_discount_shopify_app_uninstalled'
                    : 'student_discount_shopify_app_scopes_updated',
                'subject_type' => AppInstallation::class,
                'subject_id' => $installation->id,
                'metadata' => [
                    'scope' => 'store',
                    'environment' => (string) config('student_discount.environment'),
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
            throw new StudentDiscountException('INVALID_SCOPE_UPDATE_PAYLOAD', '权限更新 Webhook 缺少有效的 current 列表。');
        }

        $scopes = [];
        foreach ($current as $scope) {
            if (! is_string($scope) || ! preg_match('/^[a-z][a-z0-9_]{0,99}$/', $scope)) {
                throw new StudentDiscountException('INVALID_SCOPE_UPDATE_PAYLOAD', '权限更新 Webhook 包含无效权限。');
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
            ->whereHas('shopifyConnection', fn ($query) => $query->where('status', 'active'))
            ->with([
                'organization',
                'shopifyConnection' => fn ($query) => $query->where('status', 'active'),
            ])
            ->first();
        if (! $store || ! $store->organization || ! $store->shopifyConnection) {
            throw new StudentDiscountException('STORE_NOT_CONNECTED', 'Webhook 店铺尚未连接 DecoAdmin。', 404);
        }

        return $store;
    }

    private function configuredApp(): App
    {
        $handle = trim((string) config('student_discount.active.handle'));
        $name = trim((string) config('student_discount.active.name'));
        $clientId = trim((string) config('student_discount.active.client_id'));
        $secret = (string) config('student_discount.active.client_secret');
        if ($handle === '' || $name === '' || $clientId === '' || $secret === '') {
            throw new StudentDiscountException('STUDENT_DISCOUNT_APP_NOT_CONFIGURED', '学生优惠 App 当前环境尚未配置。', 503);
        }

        $app = App::withTrashed()->where('handle', $handle)->lockForUpdate()->first();
        if ($app && ($app->organization_id !== null
            || (filled($app->client_id) && ! hash_equals((string) $app->client_id, $clientId)))) {
            throw new StudentDiscountException('STUDENT_DISCOUNT_APP_REGISTRY_CONFLICT', '学生优惠 App 注册信息冲突。', 409);
        }
        $app ??= new App(['handle' => $handle]);
        $scopes = array_values((array) config('student_discount.required_scopes', []));
        $apiVersion = (string) config('shopify.api_version');
        $settings = [
            'managed_by' => 'student_discount_config',
            'environment' => (string) config('student_discount.environment'),
        ];
        $secretMatches = is_string($app->client_secret_encrypted)
            && hash_equals($app->client_secret_encrypted, $secret);
        $needsUpdate = ! $app->exists
            || $app->trashed()
            || $app->name !== $name
            || $app->client_id !== $clientId
            || ! $secretMatches
            || $app->distribution !== 'custom'
            || $app->status !== 'active'
            || $app->scopes !== $scopes
            || $app->webhook_api_version !== $apiVersion
            || $app->settings !== $settings;
        if ($needsUpdate) {
            $app->fill([
                'organization_id' => null,
                'name' => $name,
                'client_id' => $clientId,
                'client_secret_encrypted' => $secret,
                'distribution' => 'custom',
                'status' => 'active',
                'scopes' => $scopes,
                'webhook_api_version' => $apiVersion,
                'settings' => $settings,
            ]);
            $app->deleted_at = null;
            $app->save();
        }

        return $app;
    }
}
