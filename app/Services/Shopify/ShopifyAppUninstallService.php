<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;

class ShopifyAppUninstallService
{
    private const UNINSTALL_MUTATION = <<<'GRAPHQL'
        mutation UninstallCurrentApp {
          appUninstall {
            app { id }
            userErrors { field message }
          }
        }
        GRAPHQL;

    public function __construct(
        private ShopifyGraphQLClient $client,
        private ShopifyConnectionLifecycleService $lifecycle,
    ) {}

    public function uninstall(Store $store, User $actor): ShopifyConnection
    {
        $connection = $store->shopifyConnection()
            ->with('appInstallations')
            ->first();

        if (! $connection || ! filled($connection->access_token_encrypted)) {
            throw new ShopifyApiException('当前店铺没有可用于卸载的 Shopify 授权。');
        }

        $payload = $this->client->executeSyncQuery($connection, self::UNINSTALL_MUTATION);
        $errors = data_get($payload, 'data.appUninstall.userErrors', []);

        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->implode('；');
            throw new ShopifyApiException($message !== '' ? $message : 'Shopify 应用卸载失败。');
        }

        if (! is_string(data_get($payload, 'data.appUninstall.app.id'))) {
            throw new ShopifyApiException('Shopify 未返回有效的应用卸载结果。');
        }

        return $this->lifecycle->markUninstalled(
            $connection,
            '管理员从 decoAdmin 真正卸载 Shopify 应用。',
            $actor,
        );
    }
}
