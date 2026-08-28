<?php

namespace App\Services\AfterShip;

use App\Exceptions\ShopifyOAuthException;
use App\Models\App;
use Illuminate\Support\Facades\DB;

class AfterShipAppRegistryService
{
    /** @return array{client_id: string, client_secret: string, name: string, handle: string, app_url: string, launch_url: string, redirect_uri: string} */
    public function credentials(): array
    {
        $credentials = [
            'client_id' => trim((string) config('aftership.active.client_id')),
            'client_secret' => (string) config('aftership.active.client_secret'),
            'name' => trim((string) config('aftership.active.name')),
            'handle' => trim((string) config('aftership.active.handle')),
            'app_url' => rtrim((string) config('aftership.active.app_url'), '/'),
            'launch_url' => (string) config('aftership.active.launch_url'),
            'redirect_uri' => (string) config('aftership.active.redirect_uri'),
        ];

        if (in_array('', $credentials, true)) {
            throw new ShopifyOAuthException('Deco AfterShip 当前环境尚未配置完整。');
        }

        return $credentials;
    }

    public function configuredApp(): App
    {
        $credentials = $this->credentials();

        return DB::transaction(function () use ($credentials): App {
            $app = App::withTrashed()
                ->where('handle', $credentials['handle'])
                ->lockForUpdate()
                ->first();

            if ($app && ($app->organization_id !== null
                || (filled($app->client_id) && ! hash_equals((string) $app->client_id, $credentials['client_id'])))) {
                throw new ShopifyOAuthException('Deco AfterShip 应用注册信息冲突。');
            }

            $app ??= new App(['handle' => $credentials['handle']]);
            $scopes = array_values((array) config('aftership.required_scopes', []));
            $settings = [
                'managed_by' => 'aftership_config',
                'environment' => (string) config('aftership.environment'),
                'oauth_token_storage' => 'app_installation',
                'app_url' => $credentials['app_url'],
            ];
            $secretMatches = is_string($app->client_secret_encrypted)
                && hash_equals($app->client_secret_encrypted, $credentials['client_secret']);
            $needsUpdate = ! $app->exists
                || $app->trashed()
                || $app->name !== $credentials['name']
                || $app->client_id !== $credentials['client_id']
                || ! $secretMatches
                || $app->distribution !== 'custom'
                || $app->status !== 'active'
                || $app->scopes !== $scopes
                || $app->redirect_uris !== [$credentials['redirect_uri']]
                || $app->webhook_api_version !== (string) config('shopify.api_version')
                || $app->settings !== $settings;

            if ($needsUpdate) {
                $app->fill([
                    'organization_id' => null,
                    'name' => $credentials['name'],
                    'client_id' => $credentials['client_id'],
                    'client_secret_encrypted' => $credentials['client_secret'],
                    'distribution' => 'custom',
                    'status' => 'active',
                    'scopes' => $scopes,
                    'redirect_uris' => [$credentials['redirect_uri']],
                    'webhook_api_version' => (string) config('shopify.api_version'),
                    'settings' => $settings,
                ]);
                $app->deleted_at = null;
                $app->save();
            }

            return $app;
        });
    }
}
