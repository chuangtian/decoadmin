<?php

namespace App\Services\Shopify\Webhooks;

use App\Exceptions\ShopifyApiException;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\ShopifyConnection;
use App\Services\Shopify\ShopifyGraphQLClient;

class ShopifyWebhookSubscriptionService
{
    private const LIST_QUERY = <<<'GRAPHQL'
        query RegisteredWebhookSubscriptions($first: Int!) {
          currentAppInstallation { app { apiKey } }
          webhookSubscriptions(first: $first) {
            nodes { id topic uri }
            pageInfo { hasNextPage }
          }
        }
        GRAPHQL;

    private const CREATE_MUTATION = <<<'GRAPHQL'
        mutation RegisterWebhookSubscription($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
            webhookSubscription { id topic uri }
            userErrors { field message }
          }
        }
        GRAPHQL;

    private const UPDATE_MUTATION = <<<'GRAPHQL'
        mutation UpdateWebhookSubscription($id: ID!, $webhookSubscription: WebhookSubscriptionInput!) {
          webhookSubscriptionUpdate(id: $id, webhookSubscription: $webhookSubscription) {
            webhookSubscription { id topic uri }
            userErrors { field message }
          }
        }
        GRAPHQL;

    /** @var list<string> */
    private const TOPICS = [
        'APP_UNINSTALLED',
        'PRODUCTS_CREATE',
        'PRODUCTS_UPDATE',
        'PRODUCTS_DELETE',
        'ORDERS_CREATE',
        'ORDERS_UPDATED',
        'ORDERS_CANCELLED',
        'CUSTOMERS_CREATE',
        'CUSTOMERS_UPDATE',
        'CUSTOMERS_DELETE',
        'INVENTORY_LEVELS_UPDATE',
    ];

    public function __construct(private ShopifyGraphQLClient $client) {}

    public function supports(AppInstallation $installation): bool
    {
        $installation->loadMissing('app');

        return filled(config('shopify.client_id'))
            && $installation->app?->handle === config('shopify.app_handle', 'shopify-commerce-hub')
            && $installation->app?->client_id === config('shopify.client_id');
    }

    /**
     * @return array{created: int, updated: int, unchanged: int, endpoint: string, topics: list<string>}
     */
    public function reconcile(AppInstallation $installation): array
    {
        $installation->loadMissing(['app', 'shopifyConnection', 'store.organization']);
        $connection = $installation->shopifyConnection;
        $app = $installation->app;
        $store = $installation->store;

        // This registrar owns Commerce Hub data topics only. Independent apps
        // manage their own credentials, topics and webhook handlers.
        if (! $this->supports($installation)) {
            throw new ShopifyApiException('当前安装不属于后台主应用，禁止使用共享连接注册其 Webhook。');
        }

        if (! $connection || ! $app || ! $store || ! $store->organization
            || $installation->status !== 'active' || $store->status !== 'active' || $app->status !== 'active'
            || ! in_array($connection->status, ['connected', 'warning'], true) || $connection->uninstalled_at
            || (int) $connection->store_id !== (int) $store->getKey()
            || $connection->shop_domain !== $store->shopify_domain
            || ($app->organization_id !== null && (int) $app->organization_id !== (int) $store->organization_id)) {
            throw new ShopifyApiException('应用安装记录没有可用的 Shopify 连接。');
        }
        if (! filled($app->client_secret_encrypted) || ! filled($connection->access_token_encrypted)) {
            throw new ShopifyApiException('后台主应用缺少 Webhook 验签密钥或访问令牌，未修改订阅。');
        }

        $endpoint = rtrim((string) config('shopify.app_url'), '/')
            .route('shopify.webhooks.receive', ['app' => $app->handle], false);
        $payload = $this->client->executeSyncQuery($connection, self::LIST_QUERY, ['first' => 250]);
        if (data_get($payload, 'data.currentAppInstallation.app.apiKey') !== $app->client_id) {
            throw new ShopifyApiException('Shopify 访问令牌所属应用与 Webhook 接收应用不一致，未修改订阅。');
        }
        if (data_get($payload, 'data.webhookSubscriptions.pageInfo.hasNextPage') !== false
            || ! is_array(data_get($payload, 'data.webhookSubscriptions.nodes'))) {
            throw new ShopifyApiException('Webhook 订阅列表不完整或超过安全核对上限，未修改订阅。');
        }
        $registered = collect(data_get($payload, 'data.webhookSubscriptions.nodes', []))
            ->filter(fn ($node) => is_array($node) && is_string($node['topic'] ?? null));
        foreach ($registered->groupBy('topic') as $topic => $subscriptions) {
            if (in_array($topic, self::TOPICS, true) && $subscriptions->count() > 1) {
                throw new ShopifyApiException('同一数据主题存在多个 Webhook 订阅，未修改订阅。');
            }
        }
        $registered = $registered->keyBy('topic');
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach (self::TOPICS as $topic) {
            $existing = $registered->get($topic);

            if (! is_array($existing)) {
                $this->mutate($connection, self::CREATE_MUTATION, [
                    'topic' => $topic,
                    'webhookSubscription' => ['uri' => $endpoint],
                ], 'webhookSubscriptionCreate');
                $created++;

                continue;
            }

            if (($existing['uri'] ?? null) === $endpoint) {
                $unchanged++;

                continue;
            }

            $id = $existing['id'] ?? null;

            if (! is_string($id) || $id === '') {
                throw new ShopifyApiException("Shopify Webhook [{$topic}] 缺少订阅 ID。");
            }

            $this->mutate($connection, self::UPDATE_MUTATION, [
                'id' => $id,
                'webhookSubscription' => ['uri' => $endpoint],
            ], 'webhookSubscriptionUpdate');
            $updated++;
        }

        $result = compact('created', 'updated', 'unchanged', 'endpoint') + ['topics' => self::TOPICS];
        if ($created + $updated > 0) {
            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->getKey(),
                'action' => 'shopify.webhook_subscriptions.reconciled',
                'subject_type' => AppInstallation::class,
                'subject_id' => $installation->getKey(),
                'new_values' => $result,
                'metadata' => ['app_id' => $app->getKey(), 'app_handle' => $app->handle, 'owner_verified' => true],
            ]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function mutate(ShopifyConnection $connection, string $mutation, array $variables, string $field): void
    {
        $payload = $this->client->executeSyncQuery($connection, $mutation, $variables);
        $errors = data_get($payload, "data.{$field}.userErrors", []);

        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->implode('；');
            throw new ShopifyApiException($message !== '' ? $message : 'Shopify Webhook 订阅更新失败。');
        }

        if (! is_array(data_get($payload, "data.{$field}.webhookSubscription"))) {
            throw new ShopifyApiException('Shopify Webhook 订阅未返回有效结果。');
        }
    }
}
