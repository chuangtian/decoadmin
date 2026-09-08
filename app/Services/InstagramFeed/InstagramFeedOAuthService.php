<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\AuditLog;
use App\Models\InstagramFeedInstallation;
use App\Models\OAuthState;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use App\Services\Shopify\ShopifyOAuthHmacValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * instagram-feed App 的 Shopify 授权码授权。
 *
 * 与 Commerce Hub 主 App 同一套模式：DecoAdmin 主动发起、state 落 oauth_states
 * 且只存哈希、明文 state 走 httpOnly cookie 做 double submit、回调验 HMAC 并一次性
 * 核销 state、再用授权码换 offline token。
 *
 * 相比原来的 session token exchange，这条链路可以由后台随时重试，失败原因会写回
 * instagram_feed_installations，因此后台能看到授权状态而不只是「有没有 token」。
 *
 * 落库字段刻意与旧链路保持一致（access_token_encrypted / app_installation_id /
 * environment），所以 InstagramFeedShopifyClient 与 InstagramFeedPublisher 不需要改动。
 */
class InstagramFeedOAuthService
{
    public const STATE_COOKIE = 'instagram_feed_oauth_state';

    private const INSTALLATION_QUERY = 'query { currentAppInstallation { id accessScopes { handle } } }';

    public function __construct(
        private HttpFactory $http,
        private ShopifyOAuthHmacValidator $hmacValidator,
        private InstagramFeedAppRegistry $registry,
        private InstagramFeedShopifyClient $client,
        private InstagramFeedWebhookSubscriptionService $webhooks,
    ) {}

    /**
     * @return array{authorization_url: string, state: string, redirect_uri: string}
     */
    public function begin(Organization $organization, User $user, Store $store): array
    {
        $credentials = $this->registry->credentials();
        if ($store->organization_id !== $organization->getKey()) {
            throw new InstagramFeedException('STORE_ACCESS_DENIED', '该店铺不属于当前组织。', 403);
        }

        $domain = $this->normalizeShopDomain($store->shopify_domain);
        $app = $this->registry->configuredApp();
        $redirectUri = $this->redirectUri();
        $scopes = array_values((array) config('instagram_feed.required_scopes', []));
        $plainState = Str::random(64);

        DB::transaction(function () use ($organization, $user, $store, $app, $domain, $redirectUri, $scopes, $plainState): void {
            $lockedStore = Store::query()->lockForUpdate()->findOrFail($store->getKey());
            if ($lockedStore->organization_id !== $organization->getKey()
                || ! hash_equals($domain, $this->normalizeShopDomain($lockedStore->shopify_domain))) {
                throw new InstagramFeedException('STORE_ACCESS_DENIED', '店铺身份校验失败，请刷新后重试。', 403);
            }

            OAuthState::query()->create([
                'state_hash' => hash('sha256', $plainState),
                'organization_id' => $organization->getKey(),
                'store_id' => $store->getKey(),
                'app_id' => $app->getKey(),
                'user_id' => $user->getKey(),
                'shop_domain' => $domain,
                'redirect_uri' => $redirectUri,
                'intended_url' => route('instagram-feed.index', [$organization->getKey(), $store->getKey()]),
                'scopes' => $scopes,
                // 环境写进 state：回调时如果后端已经切到另一套环境，就不该把 token 落库。
                'payload' => ['purpose' => 'instagram_feed_install', 'environment' => $this->registry->environment()],
                'expires_at' => now()->addMinutes($this->stateTtlMinutes()),
            ]);
        });

        $query = http_build_query([
            'client_id' => $credentials['client_id'],
            'scope' => implode(',', $scopes),
            'redirect_uri' => $redirectUri,
            'state' => $plainState,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => "https://{$domain}/admin/oauth/authorize?{$query}",
            'state' => $plainState,
            'redirect_uri' => $redirectUri,
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array{store: Store, intended_url: string}
     */
    public function complete(array $query, ?string $stateCookie): array
    {
        $plainState = is_string($query['state'] ?? null) ? $query['state'] : '';
        $code = is_string($query['code'] ?? null) ? $query['code'] : '';
        if ($plainState === '' || $code === '' || ! is_string($stateCookie) || ! hash_equals($plainState, $stateCookie)) {
            throw new InstagramFeedException('INSTAGRAM_FEED_OAUTH_STATE_INVALID', 'Shopify 授权状态校验失败。', 403);
        }

        $state = OAuthState::query()
            ->with(['app', 'store', 'user'])
            ->where('state_hash', hash('sha256', $plainState))
            ->first();
        if (! $state || ! $state->app || ! $state->store || ! is_string($state->app->client_secret_encrypted)) {
            throw new InstagramFeedException('INSTAGRAM_FEED_OAUTH_STATE_INVALID', 'Shopify 授权状态无效。', 403);
        }

        $domain = $this->normalizeShopDomain((string) ($query['shop'] ?? ''));
        if (! hash_equals($state->shop_domain, $domain)
            || ! $this->hmacValidator->validate($query, $state->app->client_secret_encrypted)) {
            throw new InstagramFeedException('INSTAGRAM_FEED_OAUTH_CALLBACK_INVALID', 'Shopify 授权回调校验失败。', 403);
        }

        $environment = (string) data_get($state->payload, 'environment');
        if ($environment !== '' && $environment !== $this->registry->environment()) {
            throw new InstagramFeedException(
                'INSTAGRAM_FEED_OAUTH_ENVIRONMENT_MISMATCH',
                '授权发起环境与当前环境不一致，请重新发起授权。',
                409,
            );
        }

        $state = DB::transaction(function () use ($state): OAuthState {
            $locked = OAuthState::query()->lockForUpdate()->find($state->getKey());
            if (! $locked || $locked->consumed_at || $locked->expires_at->isPast()) {
                throw new InstagramFeedException('INSTAGRAM_FEED_OAUTH_STATE_EXPIRED', 'Shopify 授权状态已过期或已使用。', 403);
            }
            $locked->forceFill(['consumed_at' => now()])->save();

            return $locked;
        });

        $store = $state->store;
        try {
            $token = $this->exchangeCode($domain, $code);
            $this->registry->assertRequiredScopes($token['scopes']);
            $installationId = $this->readAppInstallationId($domain, $token['access_token']);
            $this->persist($store, $token, $installationId, $state->user_id);
        } catch (InstagramFeedException $exception) {
            // 失败原因写回安装记录，后台的连接状态卡片要能直接看到。
            $this->recordFailure($store, $exception);

            throw $exception;
        }

        // 授权码安装流程下 webhook 只能按店铺注册。注册失败不能推翻已经完成的授权，
        // 记一条可见的错误原因即可，之后重新授权或「检查连接」会再试一次。
        try {
            $this->webhooks->reconcile($store, $token['access_token']);
        } catch (Throwable $exception) {
            $this->recordFailure($store, $exception instanceof InstagramFeedException
                ? $exception
                : new InstagramFeedException(
                    'INSTAGRAM_FEED_WEBHOOK_SUBSCRIPTION_FAILED',
                    'Webhook 订阅注册失败：'.$exception->getMessage(),
                    502,
                ));
            report($exception);
        }

        return [
            'store' => $store,
            'intended_url' => $state->intended_url
                ?: route('instagram-feed.index', [$store->organization_id, $store->id]),
        ];
    }

    /**
     * 主动检查当前授权是否仍然可用，并把结果写回安装记录。
     *
     * @return array{status: string, message: string}
     */
    public function verify(Store $store, ?User $actor = null): array
    {
        $installation = InstagramFeedInstallation::query()->where('store_id', $store->id)->first();
        if (! $installation || ! $installation->isUsable()) {
            return [
                'status' => InstagramFeedInstallation::STATUS_DISCONNECTED,
                'message' => '该店铺尚未完成 Instagram Feed App 的 Shopify 授权。',
            ];
        }

        try {
            $installationId = $this->readAppInstallationId(
                $store->shopify_domain,
                (string) $installation->access_token_encrypted,
            );
        } catch (InstagramFeedException $exception) {
            $status = $exception->statusCode === 401
                ? InstagramFeedInstallation::STATUS_INVALID
                : InstagramFeedInstallation::STATUS_WARNING;
            $installation->fill([
                'status' => $status,
                'last_api_check' => now(),
                'last_error' => $this->safeReason($exception->getMessage()),
                'last_error_at' => now(),
            ])->save();

            return ['status' => $status, 'message' => $exception->getMessage()];
        }

        $installation->fill([
            'status' => InstagramFeedInstallation::STATUS_CONNECTED,
            'app_installation_id' => $installationId,
            'last_verified_at' => now(),
            'last_api_check' => now(),
            'last_error' => null,
            'last_error_at' => null,
        ])->save();

        $this->audit($store, $actor, 'instagram_feed_shopify_app_verified', [
            'app_installation_id' => $installationId,
        ]);

        return [
            'status' => InstagramFeedInstallation::STATUS_CONNECTED,
            'message' => 'Instagram Feed App 的 Shopify 授权正常。',
        ];
    }

    /**
     * @param  array{access_token: string, scopes: list<string>}  $token
     */
    private function persist(Store $store, array $token, string $installationId, ?int $userId): void
    {
        DB::transaction(function () use ($store, $token, $installationId, $userId): void {
            $installation = InstagramFeedInstallation::query()->firstOrNew(['store_id' => $store->id]);
            $installation->fill([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'environment' => $this->registry->environment(),
                'status' => InstagramFeedInstallation::STATUS_CONNECTED,
                'app_installation_id' => $installationId,
                'access_token_encrypted' => $token['access_token'],
                'granted_scopes' => $token['scopes'],
                'installed_by' => $userId ?? $installation->installed_by,
                'installed_at' => $installation->installed_at ?? now(),
                'uninstalled_at' => null,
                'last_verified_at' => now(),
                'last_api_check' => now(),
                'last_error' => null,
                'last_error_at' => null,
            ]);
            $installation->save();

            // 应用中心的列表、侧边栏条目和配置卡片都以 app_installations 为准。
            $this->registry->synchronizeInstallation(
                $store,
                'active',
                $token['scopes'],
                'instagram_feed_oauth',
                $installationId,
            );

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'user_id' => $userId,
                'action' => 'instagram_feed_shopify_app_authorized',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'metadata' => [
                    'scope' => 'store',
                    'environment' => $this->registry->environment(),
                    'app_installation_id' => $installationId,
                    'granted_scopes' => $token['scopes'],
                ],
            ]);
        });
    }

    /**
     * @return array{access_token: string, scopes: list<string>}
     */
    private function exchangeCode(string $shop, string $code): array
    {
        $credentials = $this->registry->credentials();

        try {
            $response = $this->http
                ->asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(20)
                ->post("https://{$shop}/admin/oauth/access_token", [
                    'client_id' => $credentials['client_id'],
                    'client_secret' => $credentials['client_secret'],
                    'code' => $code,
                ]);
        } catch (ConnectionException) {
            throw new InstagramFeedException('SHOPIFY_OAUTH_TIMEOUT', 'Shopify 授权换取令牌超时，请稍后重试。', 502);
        }

        $accessToken = $response->json('access_token');
        if ($response->failed() || ! is_string($accessToken) || $accessToken === '') {
            throw new InstagramFeedException(
                'SHOPIFY_OAUTH_TOKEN_EXCHANGE_FAILED',
                'Shopify 授权换取令牌失败（HTTP '.$response->status().'）。',
                502,
            );
        }

        $scopes = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $response->json('scope', '')),
        )));

        return ['access_token' => $accessToken, 'scopes' => $scopes];
    }

    private function readAppInstallationId(string $shop, string $accessToken): string
    {
        $payload = $this->client->graphqlWithToken($shop, $accessToken, self::INSTALLATION_QUERY);
        $installationId = data_get($payload, 'data.currentAppInstallation.id');
        if (! is_string($installationId) || $installationId === '') {
            throw new InstagramFeedException(
                'SHOPIFY_APP_NOT_INSTALLED',
                '未找到当前 Instagram Feed App 的安装记录。',
                409,
            );
        }

        return $installationId;
    }

    private function recordFailure(Store $store, InstagramFeedException $exception): void
    {
        $installation = InstagramFeedInstallation::query()->where('store_id', $store->id)->first();
        if (! $installation) {
            return;
        }

        $installation->fill([
            'status' => $exception->statusCode === 401
                ? InstagramFeedInstallation::STATUS_INVALID
                : InstagramFeedInstallation::STATUS_WARNING,
            'last_api_check' => now(),
            'last_error' => $this->safeReason($exception->getMessage()),
            'last_error_at' => now(),
        ])->save();
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Store $store, ?User $actor, string $action, array $metadata): void
    {
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => Store::class,
            'subject_id' => $store->id,
            'metadata' => ['scope' => 'store', 'environment' => $this->registry->environment(), ...$metadata],
        ]);
    }

    /** 错误原因要落库并展示给运营，先抹掉可能夹带的令牌再截断。 */
    private function safeReason(string $reason): string
    {
        $redacted = preg_replace('/\b(shp(at|ca|pa|ss)_[A-Za-z0-9]+)\b/', '[redacted]', $reason) ?? $reason;

        return Str::limit(trim($redacted), 1000, '');
    }

    private function redirectUri(): string
    {
        $redirectUri = route('instagram-feed.shopify-oauth.callback');
        $parts = parse_url($redirectUri);
        $isLocal = in_array($parts['host'] ?? null, ['localhost', '127.0.0.1'], true);

        if (! filter_var($redirectUri, FILTER_VALIDATE_URL)
            || ! in_array($parts['scheme'] ?? null, $isLocal ? ['http', 'https'] : ['https'], true)) {
            throw new InstagramFeedException(
                'INSTAGRAM_FEED_OAUTH_REDIRECT_INVALID',
                'Shopify 授权回调地址配置无效；远程地址必须使用 HTTPS。',
                500,
            );
        }

        return $redirectUri;
    }

    private function stateTtlMinutes(): int
    {
        return (int) config('instagram_feed.oauth_state_ttl_minutes', config('shopify.state_ttl_minutes', 10));
    }

    private function normalizeShopDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $domain = rtrim(explode('/', $domain, 2)[0], '.');

        if (preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain) !== 1) {
            throw new InstagramFeedException('STORE_NOT_CONNECTED', '店铺域名无效。', 404);
        }

        return $domain;
    }
}
