<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyWebhookException;
use App\Jobs\ProcessWebhookEventJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\WebhookEvent;
use App\Services\Shopify\Webhooks\WebhookEventStateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

class ShopifyWebhookService
{
    public function __construct(
        private ShopifyWebhookHmacValidator $hmacValidator,
        private WebhookEventStateService $states,
    ) {}

    /**
     * @param  array<string, string|null>  $headers
     * @return array{event: WebhookEvent, created: bool}
     */
    public function receive(App $app, string $rawPayload, array $headers): array
    {
        if (strlen($rawPayload) > 10 * 1024 * 1024) {
            throw new ShopifyWebhookException('Webhook 事件载荷超过允许大小。', 413);
        }

        $secret = is_string($app->client_secret_encrypted) ? $app->client_secret_encrypted : '';

        if (! $this->hmacValidator->validate($rawPayload, $headers['hmac'] ?? null, $secret)) {
            throw new ShopifyWebhookException('Webhook 签名验证失败。', 401);
        }

        $webhookId = trim((string) ($headers['webhook_id'] ?? ''));
        $topic = trim((string) ($headers['topic'] ?? ''));
        $shopDomain = strtolower(trim((string) ($headers['shop_domain'] ?? '')));
        $apiVersion = trim((string) ($headers['api_version'] ?? ''));

        if (! Str::isUuid($webhookId)
            || $topic === ''
            || mb_strlen($topic) > 160
            || mb_strlen($apiVersion) > 20
            || ! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shopDomain)) {
            throw new ShopifyWebhookException('Webhook 请求缺少有效的事件标识、主题或店铺域名。', 422);
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ShopifyWebhookException('Webhook 事件载荷不是有效 JSON。', 422);
        }

        if (! is_array($payload)) {
            throw new ShopifyWebhookException('Webhook 事件载荷格式无效。', 422);
        }

        $installationQuery = AppInstallation::query()
            ->whereBelongsTo($app)
            ->whereHas('shopifyConnection', fn ($query) => $query->where('shop_domain', $shopDomain))
            ->with(['store.organization', 'shopifyConnection']);

        if ($topic !== 'app/uninstalled') {
            $installationQuery->where('status', 'active');
        }

        $installation = $installationQuery->first();

        if (! $installation || ! $installation->store || ! $installation->shopifyConnection) {
            throw new ShopifyWebhookException('Webhook 店铺尚未安装当前应用。', 404);
        }

        $payloadHash = hash('sha256', $rawPayload);
        [$event, $created] = DB::transaction(function () use ($app, $installation, $webhookId, $topic, $shopDomain, $apiVersion, $headers, $rawPayload, $payloadHash): array {
            $event = WebhookEvent::query()->where('webhook_id', $webhookId)->lockForUpdate()->first();

            if ($event) {
                if ($event->app_id !== $app->getKey() || ! hash_equals((string) $event->payload_sha256, $payloadHash)) {
                    throw new ShopifyWebhookException('Webhook 事件 ID 与已保存事件冲突。', 409);
                }

                return [$event, false];
            }

            $event = WebhookEvent::query()->create([
                'webhook_id' => $webhookId,
                'organization_id' => $installation->store->organization_id,
                'store_id' => $installation->store_id,
                'shopify_connection_id' => $installation->shopify_connection_id,
                'app_id' => $app->getKey(),
                'topic' => $topic,
                'api_version' => $apiVersion ?: null,
                'headers' => array_filter([
                    'webhook_id' => $webhookId,
                    'topic' => $topic,
                    'shop_domain' => $shopDomain,
                    'api_version' => $apiVersion ?: null,
                    'triggered_at' => $headers['triggered_at'] ?? null,
                ], fn ($value) => $value !== null && $value !== ''),
                'payload' => ['storage' => 'encrypted'],
                'payload_encrypted' => $rawPayload,
                'payload_sha256' => $payloadHash,
                'status' => 'received',
                'attempts' => 0,
                'received_at' => now(),
            ]);

            return [$event, true];
        });

        if ($created) {
            $this->states->markQueued($event);
            ProcessWebhookEventJob::dispatch($event->getKey());
        }

        return ['event' => $event, 'created' => $created];
    }
}
