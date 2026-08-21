<?php

namespace App\Services\MicrosoftAds;

use App\Exceptions\MicrosoftAdsOAuthException;
use App\Models\Store;
use App\Models\User;
use App\Services\StoreBusinessCredentialService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;

class MicrosoftAdsOAuthService
{
    public const SESSION_KEY = 'microsoft_ads_oauth_state';

    private const AUTHORIZATION_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize';

    private const TOKEN_URL = 'https://login.microsoftonline.com/common/oauth2/v2.0/token';

    private const SCOPE = 'openid offline_access https://ads.microsoft.com/msads.manage';

    public function __construct(
        private HttpFactory $http,
        private StoreBusinessCredentialService $credentials,
    ) {}

    /**
     * @return array{
     *     authorization_url: string,
     *     state: string,
     *     session: array{state_hash: string, organization_id: int, store_id: int, user_id: int, redirect_uri: string, expires_at: int}
     * }
     */
    public function begin(Store $store, User $user): array
    {
        $clientId = $this->credential($store, 'client_id');
        $clientSecret = $this->credential($store, 'client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new MicrosoftAdsOAuthException('请先保存 Microsoft Ads Client ID 和 Client Secret。');
        }

        $redirectUri = $this->redirectUri();
        $plainState = Str::random(64);
        $query = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => self::SCOPE,
            'prompt' => 'consent',
            'state' => $plainState,
        ], '', '&', PHP_QUERY_RFC3986);

        return [
            'authorization_url' => self::AUTHORIZATION_URL.'?'.$query,
            'state' => $plainState,
            'session' => [
                'state_hash' => hash('sha256', $plainState),
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->getKey(),
                'user_id' => (int) $user->getKey(),
                'redirect_uri' => $redirectUri,
                'expires_at' => now()->addMinutes((int) config('services.bing_ads.state_ttl_minutes', 10))->getTimestamp(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $stateSession
     */
    public function complete(Store $store, User $user, array $query, ?array $stateSession): void
    {
        $plainState = is_string($query['state'] ?? null) ? $query['state'] : '';

        if (! $this->validState($store, $user, $plainState, $stateSession)) {
            throw new MicrosoftAdsOAuthException('Microsoft Ads 授权状态已失效，请重新连接。');
        }

        if (is_string($query['error'] ?? null)) {
            throw new MicrosoftAdsOAuthException('Microsoft Ads 授权已取消，请重新连接。');
        }

        $code = is_string($query['code'] ?? null) ? $query['code'] : '';

        if ($code === '') {
            throw new MicrosoftAdsOAuthException('Microsoft Ads 未返回有效授权码，请重新连接。');
        }

        $clientId = $this->credential($store, 'client_id');
        $clientSecret = $this->credential($store, 'client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw new MicrosoftAdsOAuthException('Microsoft Ads OAuth 凭证已变更，请重新连接。');
        }

        $response = $this->http
            ->asForm()
            ->acceptJson()
            ->timeout(20)
            ->post(self::TOKEN_URL, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => self::SCOPE,
                'code' => $code,
                'redirect_uri' => $stateSession['redirect_uri'],
                'grant_type' => 'authorization_code',
            ]);
        $payload = $response->json();

        if ($response->failed() || ! is_array($payload) || ! is_string($payload['access_token'] ?? null)) {
            throw new MicrosoftAdsOAuthException('Microsoft Ads 访问令牌交换失败，请重新连接。');
        }

        $refreshToken = is_string($payload['refresh_token'] ?? null) ? trim($payload['refresh_token']) : '';

        if ($refreshToken === '') {
            if ($this->credential($store, 'refresh_token') !== '') {
                return;
            }

            throw new MicrosoftAdsOAuthException('Microsoft 未返回 Refresh Token，请重新授权。');
        }

        $this->credentials->update($store, 'bing_ads', 'refresh_token', $refreshToken, $user);
    }

    /** @param array<string, mixed>|null $stateSession */
    private function validState(Store $store, User $user, string $plainState, ?array $stateSession): bool
    {
        if ($plainState === '' || ! is_array($stateSession)) {
            return false;
        }

        $stateHash = $stateSession['state_hash'] ?? null;

        return is_string($stateHash)
            && hash_equals($stateHash, hash('sha256', $plainState))
            && (int) ($stateSession['organization_id'] ?? 0) === (int) $store->organization_id
            && (int) ($stateSession['store_id'] ?? 0) === (int) $store->getKey()
            && (int) ($stateSession['user_id'] ?? 0) === (int) $user->getKey()
            && (int) ($stateSession['expires_at'] ?? 0) >= now()->getTimestamp()
            && is_string($stateSession['redirect_uri'] ?? null)
            && hash_equals($this->redirectUri(), $stateSession['redirect_uri']);
    }

    private function credential(Store $store, string $key): string
    {
        return trim((string) $this->credentials->value($store, 'bing_ads', $key));
    }

    private function redirectUri(): string
    {
        $redirectUri = (string) (config('services.bing_ads.redirect_uri') ?: route('bing-ads.oauth.callback'));
        $parts = parse_url($redirectUri);
        $isLocal = in_array($parts['host'] ?? null, ['localhost', '127.0.0.1'], true);

        if (! filter_var($redirectUri, FILTER_VALIDATE_URL)
            || ! in_array($parts['scheme'] ?? null, $isLocal ? ['http', 'https'] : ['https'], true)) {
            throw new MicrosoftAdsOAuthException('Microsoft Ads OAuth 回调地址无效；远程地址必须使用 HTTPS。');
        }

        return $redirectUri;
    }
}
