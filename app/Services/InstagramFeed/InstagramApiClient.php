<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Instagram API with Instagram Login（Basic Display API 已于 2024-12 下线）。
 * https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/business-login
 *
 * 需要 Instagram 专业账号（Business / Creator）+ Meta 应用。自己的账号用
 * Standard Access 即可，不需要 App Review。
 */
class InstagramApiClient
{
    private const AUTHORIZE_ENDPOINT = 'https://www.instagram.com/oauth/authorize';

    private const TOKEN_ENDPOINT = 'https://api.instagram.com/oauth/access_token';

    private const GRAPH_HOST = 'https://graph.instagram.com';

    /** 长效 token 默认 60 天。 */
    private const DEFAULT_TOKEN_LIFETIME_SECONDS = 5184000;

    private const MEDIA_FIELDS = 'id,caption,media_type,media_product_type,media_url,permalink,thumbnail_url,timestamp,like_count,comments_count';

    /** 部分账号拿不到 like_count / comments_count，首次失败后降级重试。 */
    private const MEDIA_FIELDS_FALLBACK = 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp';

    public function __construct(private HttpFactory $http) {}

    public function isConfigured(): bool
    {
        return filled(config('instagram_feed.instagram.app_id'))
            && filled(config('instagram_feed.instagram.app_secret'));
    }

    public function redirectUri(): string
    {
        return (string) config('instagram_feed.instagram.redirect_uri');
    }

    public function buildAuthorizeUrl(string $state): string
    {
        $config = $this->config();
        $query = http_build_query([
            'client_id' => $config['app_id'],
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(',', (array) config('instagram_feed.instagram.scopes', [])),
            'state' => $state,
        ]);

        return self::AUTHORIZE_ENDPOINT.'?'.$query;
    }

    /**
     * 授权码换短期 token（有效期 1 小时）。
     *
     * @return array{access_token: string, ig_user_id: string|null}
     */
    public function exchangeCodeForToken(string $code): array
    {
        $config = $this->config();

        $response = $this->send(fn (): Response => $this->http
            ->asForm()
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->post(self::TOKEN_ENDPOINT, [
                'client_id' => $config['app_id'],
                'client_secret' => $config['app_secret'],
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri(),
                // Meta 会在 code 结尾附加 `#_`，必须去掉。
                'code' => preg_replace('/#_$/', '', $code),
            ]), 'INSTAGRAM_TOKEN_EXCHANGE_FAILED', '换取 Instagram 授权令牌失败。');

        // 部分响应把结果包在 data[0] 里。
        $payload = is_array($response->json('data.0')) ? $response->json('data.0') : $response->json();
        $accessToken = data_get($payload, 'access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw new InstagramFeedException('INSTAGRAM_TOKEN_EXCHANGE_FAILED', 'Instagram 未返回访问令牌。', 502);
        }
        $igUserId = data_get($payload, 'user_id');

        return [
            'access_token' => $accessToken,
            'ig_user_id' => is_scalar($igUserId) && (string) $igUserId !== '' ? (string) $igUserId : null,
        ];
    }

    /**
     * 短期 token 换 60 天长效 token。
     *
     * @return array{access_token: string, expires_at: CarbonImmutable}
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $config = $this->config();

        $response = $this->send(fn (): Response => $this->http
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->get(self::GRAPH_HOST.'/access_token', [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $config['app_secret'],
                'access_token' => $shortLivedToken,
            ]), 'INSTAGRAM_LONG_LIVED_TOKEN_FAILED', '换取 Instagram 长效令牌失败。');

        return $this->readToken($response, 'INSTAGRAM_LONG_LIVED_TOKEN_FAILED');
    }

    /**
     * 刷新长效 token。token 需已存在 24 小时以上且未过期。
     *
     * @return array{access_token: string, expires_at: CarbonImmutable}
     */
    public function refreshLongLivedToken(string $accessToken): array
    {
        $response = $this->send(fn (): Response => $this->http
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->get(self::GRAPH_HOST.'/refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $accessToken,
            ]), 'INSTAGRAM_TOKEN_REFRESH_FAILED', '刷新 Instagram 长效令牌失败。');

        return $this->readToken($response, 'INSTAGRAM_TOKEN_REFRESH_FAILED');
    }

    /** @return array{ig_user_id: string, username: string, account_type: string|null, profile_picture_url: string|null} */
    public function fetchProfile(string $accessToken): array
    {
        $response = $this->send(fn (): Response => $this->http
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->get($this->graphUrl('/me'), [
                'fields' => 'user_id,username,account_type,profile_picture_url',
                'access_token' => $accessToken,
            ]), 'INSTAGRAM_PROFILE_FETCH_FAILED', '读取 Instagram 账号信息失败。');

        $igUserId = $response->json('user_id') ?? $response->json('id');
        $username = $response->json('username');
        if (! is_scalar($igUserId) || (string) $igUserId === '' || ! is_string($username) || $username === '') {
            throw new InstagramFeedException('INSTAGRAM_PROFILE_INCOMPLETE', 'Instagram 账号信息不完整。', 502);
        }

        return [
            'ig_user_id' => (string) $igUserId,
            'username' => $username,
            'account_type' => is_string($response->json('account_type')) ? $response->json('account_type') : null,
            'profile_picture_url' => is_string($response->json('profile_picture_url')) ? $response->json('profile_picture_url') : null,
        ];
    }

    /**
     * 拉取账号媒体，按分页游标一直翻到底。
     *
     * @return list<array<string, mixed>>
     */
    public function fetchMedia(string $accessToken, int $maxItems): array
    {
        $collected = [];
        $fields = self::MEDIA_FIELDS;
        $nextUrl = null;
        $pageSize = (int) config('instagram_feed.media.page_size', 50);
        // 游标重复说明分页出问题了，再翻下去就是死循环。
        $seenCursors = [];

        while (count($collected) < $maxItems) {
            if (is_string($nextUrl)) {
                $response = $this->request(fn (): Response => $this->http
                    ->acceptJson()
                    ->connectTimeout(5)
                    ->timeout(30)
                    ->get($nextUrl));
            } else {
                $currentFields = $fields;
                $response = $this->request(fn (): Response => $this->http
                    ->acceptJson()
                    ->connectTimeout(5)
                    ->timeout(30)
                    ->get($this->graphUrl('/me/media'), [
                        'fields' => $currentFields,
                        'limit' => min($pageSize, $maxItems - count($collected)),
                        'access_token' => $accessToken,
                    ]));
            }

            if ($response->failed()) {
                // 首次请求失败且用的是完整字段时，降级重试一次。
                if ($nextUrl === null && $fields === self::MEDIA_FIELDS) {
                    $fields = self::MEDIA_FIELDS_FALLBACK;

                    continue;
                }

                throw new InstagramFeedException(
                    'INSTAGRAM_MEDIA_FETCH_FAILED',
                    '读取 Instagram 媒体失败：'.MetaGraphErrorReader::read($response),
                    502,
                );
            }

            $batch = $response->json('data');
            $batch = is_array($batch) ? $batch : [];
            foreach ($batch as $raw) {
                if (is_array($raw)) {
                    $collected[] = MetaMediaMapper::map($raw);
                }
            }

            $next = $response->json('paging.next');
            if (! is_string($next) || $next === '' || $batch === [] || in_array($next, $seenCursors, true)) {
                break;
            }
            $seenCursors[] = $next;
            $nextUrl = $next;
        }

        return array_slice($collected, 0, $maxItems);
    }

    /** @return array{app_id: string, app_secret: string} */
    private function config(): array
    {
        $appId = trim((string) config('instagram_feed.instagram.app_id'));
        $appSecret = (string) config('instagram_feed.instagram.app_secret');
        if ($appId === '' || $appSecret === '') {
            throw new InstagramFeedException(
                'INSTAGRAM_PROVIDER_NOT_CONFIGURED',
                'Instagram 账号授权尚未配置，请先补齐 Meta 应用凭证。',
                503,
            );
        }

        return ['app_id' => $appId, 'app_secret' => $appSecret];
    }

    private function graphUrl(string $path): string
    {
        return self::GRAPH_HOST.'/'.trim((string) config('instagram_feed.instagram.graph_version', 'v25.0'), '/').$path;
    }

    /** @return array{access_token: string, expires_at: CarbonImmutable} */
    private function readToken(Response $response, string $errorCode): array
    {
        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw new InstagramFeedException($errorCode, 'Instagram 未返回访问令牌。', 502);
        }
        $expiresIn = $response->json('expires_in');
        $lifetime = is_numeric($expiresIn) ? (int) $expiresIn : self::DEFAULT_TOKEN_LIFETIME_SECONDS;

        return [
            'access_token' => $accessToken,
            'expires_at' => CarbonImmutable::now()->addSeconds($lifetime),
        ];
    }

    /** @param callable(): Response $request */
    private function send(callable $request, string $errorCode, string $message): Response
    {
        $response = $this->request($request);
        if ($response->failed()) {
            throw new InstagramFeedException($errorCode, $message.MetaGraphErrorReader::suffix($response), 502);
        }

        return $response;
    }

    /** @param callable(): Response $request */
    private function request(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new InstagramFeedException('INSTAGRAM_API_TIMEOUT', 'Instagram 接口请求超时，请稍后重试。', 502);
        }
    }
}
