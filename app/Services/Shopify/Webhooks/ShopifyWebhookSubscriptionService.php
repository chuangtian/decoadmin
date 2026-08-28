<?php

namespace App\Services\Shopify\Webhooks;

use App\Exceptions\ShopifyApiException;
use App\Models\AppInstallation;
use App\Services\Shopify\ShopifyGraphQLClient;

class ShopifyWebhookSubscriptionService
{
    private const LIST_QUERY = <<<'GRAPHQL'
        query RegisteredWebhookSubscriptions($first: Int!) {
          webhookSubscriptions(first: $first) {
            nodes { id topic uri }
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

    /**
     * @return array{created: int, updated: int, unchanged: int, endpoint: string, topics: list<string>}
     */
    public function reconcile(AppInstallation $installation): array
    {
        $installation->loadMissing(['app', 'shopifyConnection']);
        $connection = $installation->shopifyConnection;
        $app = $installation->app;

        if (! $connection || ! $app || $installation->status !== 'active') {
            throw new ShopifyApiException('应用安装记录没有可用的 Shopify 连接。');
        }

        $appUrl = rtrim((string) data_get($app->settings, 'app_url', config('shopify.app_url')), '/');
        $endpoint = $appUrl
            .route('shopify.webhooks.receive', ['app' => $app->handle], false);
        $payload = $this->execute($installation, self::LIST_QUERY, ['first' => 250]);
        $registered = collect(data_get($payload, 'data.webhookSubscriptions.nodes', []))
            ->filter(fn ($node) => is_array($node) && is_string($node['topic'] ?? null))
            ->keyBy('topic');
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach (self::TOPICS as $topic) {
            $existing = $registered->get($topic);

            if (! is_array($existing)) {
                $this->mutate($installation, self::CREATE_MUTATION, [
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

            $this->mutate($installation, self::UPDATE_MUTATION, [
                'id' => $id,
                'webhookSubscription' => ['uri' => $endpoint],
            ], 'webhookSubscriptionUpdate');
            $updated++;
        }

        return compact('created', 'updated', 'unchanged', 'endpoint') + ['topics' => self::TOPICS];
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function mutate(AppInstallation $installation, string $mutation, array $variables, string $field): void
    {
        $payload = $this->execute($installation, $mutation, $variables);
        $errors = data_get($payload, "data.{$field}.userErrors", []);

        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->implode('；');
            throw new ShopifyApiException($message !== '' ? $message : 'Shopify Webhook 订阅更新失败。');
        }

        if (! is_array(data_get($payload, "data.{$field}.webhookSubscription"))) {
            throw new ShopifyApiException('Shopify Webhook 订阅未返回有效结果。');
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function execute(AppInstallation $installation, string $query, array $variables): array
    {
        $connection = $installation->shopifyConnection;
        if (! $connection) {
            throw new ShopifyApiException('应用安装记录没有可用的 Shopify 连接。');
        }

        $accessToken = $installation->access_token_encrypted;
        if (! is_string($accessToken) || $accessToken === '') {
            return $this->client->executeSyncQuery($connection, $query, $variables);
        }

        $payload = $this->client->queryWithAccessToken(
            $connection->shop_domain,
            $accessToken,
            $query,
            $variables,
            30,
            $installation->app?->webhook_api_version,
        );

        return [
            'data' => is_array($payload['data'] ?? null) ? $payload['data'] : [],
            'extensions' => is_array($payload['extensions'] ?? null) ? $payload['extensions'] : [],
        ];
    }
}
