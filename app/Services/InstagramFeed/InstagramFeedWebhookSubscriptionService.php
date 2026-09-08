<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\Store;

/**
 * instagram-feed App 的 webhook 订阅（按店铺注册）。
 *
 * 授权码安装流程（use_legacy_install_flow = true）下 Shopify 不接受 TOML 里的
 * app 级订阅，所以和 Commerce Hub 一样改成后端用本 App 的 offline token 逐店注册。
 * 订阅地址取当前环境的 app_url，避免 test 的安装把回调指到生产域名。
 */
class InstagramFeedWebhookSubscriptionService
{
    private const LIST_QUERY = <<<'GRAPHQL'
        query InstagramFeedWebhookSubscriptions($first: Int!) {
          webhookSubscriptions(first: $first) {
            nodes { id topic endpoint { __typename ... on WebhookHttpEndpoint { callbackUrl } } }
          }
        }
        GRAPHQL;

    private const CREATE_MUTATION = <<<'GRAPHQL'
        mutation CreateInstagramFeedWebhook($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
            webhookSubscription { id topic }
            userErrors { field message }
          }
        }
        GRAPHQL;

    private const UPDATE_MUTATION = <<<'GRAPHQL'
        mutation UpdateInstagramFeedWebhook($id: ID!, $webhookSubscription: WebhookSubscriptionInput!) {
          webhookSubscriptionUpdate(id: $id, webhookSubscription: $webhookSubscription) {
            webhookSubscription { id topic }
            userErrors { field message }
          }
        }
        GRAPHQL;

    /** @var list<string> */
    private const TOPICS = ['APP_UNINSTALLED', 'APP_SCOPES_UPDATE'];

    public function __construct(private InstagramFeedShopifyClient $client) {}

    /**
     * @return array{created: int, updated: int, unchanged: int, endpoint: string, topics: list<string>}
     */
    public function reconcile(Store $store, string $accessToken): array
    {
        $endpoint = $this->endpoint();
        $payload = $this->client->graphqlWithToken(
            $store->shopify_domain,
            $accessToken,
            self::LIST_QUERY,
            ['first' => 100],
        );
        $registered = collect(data_get($payload, 'data.webhookSubscriptions.nodes', []))
            ->filter(fn (mixed $node): bool => is_array($node) && is_string($node['topic'] ?? null))
            ->keyBy('topic');
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        foreach (self::TOPICS as $topic) {
            $existing = $registered->get($topic);

            if (! is_array($existing)) {
                $this->mutate($store, $accessToken, self::CREATE_MUTATION, [
                    'topic' => $topic,
                    'webhookSubscription' => ['callbackUrl' => $endpoint, 'format' => 'JSON'],
                ], 'webhookSubscriptionCreate');
                $created++;

                continue;
            }

            if (data_get($existing, 'endpoint.callbackUrl') === $endpoint) {
                $unchanged++;

                continue;
            }

            $id = $existing['id'] ?? null;
            if (! is_string($id) || $id === '') {
                throw new InstagramFeedException(
                    'INSTAGRAM_FEED_WEBHOOK_SUBSCRIPTION_INVALID',
                    "Shopify Webhook [{$topic}] 缺少订阅 ID。",
                    502,
                );
            }

            $this->mutate($store, $accessToken, self::UPDATE_MUTATION, [
                'id' => $id,
                'webhookSubscription' => ['callbackUrl' => $endpoint, 'format' => 'JSON'],
            ], 'webhookSubscriptionUpdate');
            $updated++;
        }

        return ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged, 'endpoint' => $endpoint, 'topics' => self::TOPICS];
    }

    public function endpoint(): string
    {
        return rtrim((string) config('instagram_feed.active.app_url'), '/')
            .route('instagram-feed.shopify-app.webhooks', absolute: false);
    }

    /** @param array<string, mixed> $variables */
    private function mutate(Store $store, string $accessToken, string $mutation, array $variables, string $field): void
    {
        $payload = $this->client->graphqlWithToken($store->shopify_domain, $accessToken, $mutation, $variables);
        $errors = data_get($payload, "data.{$field}.userErrors", []);

        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->implode('；');
            throw new InstagramFeedException(
                'INSTAGRAM_FEED_WEBHOOK_SUBSCRIPTION_FAILED',
                $message !== '' ? $message : 'Shopify Webhook 订阅更新失败。',
                502,
            );
        }

        if (! is_array(data_get($payload, "data.{$field}.webhookSubscription"))) {
            throw new InstagramFeedException(
                'INSTAGRAM_FEED_WEBHOOK_SUBSCRIPTION_FAILED',
                'Shopify Webhook 订阅未返回有效结果。',
                502,
            );
        }
    }
}
