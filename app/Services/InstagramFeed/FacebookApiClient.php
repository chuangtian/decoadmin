<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Instagram API with Facebook Login。
 * https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/get-started
 *
 * 前提：IG 专业账号 + 一个关联它的 Facebook 主页，且授权者对该主页有操作权限。
 * 流程：FB OAuth → 换长效用户 token → /me/accounts 拿主页和主页 token
 *      → 主页上的 instagram_business_account 就是要用的 IG 账号
 *      → 后续用主页 token 查 /{ig-user-id}/media
 *
 * 这里用的是 Facebook 应用的 App ID / Secret，与 Instagram 那组凭证不是同一份。
 */
class FacebookApiClient
{
    private const GRAPH_HOST = 'https://graph.facebook.com';

    private const DIALOG_HOST = 'https://www.facebook.com';

    /** 用户 token 一般 60 天；主页 token 由它派生且不过期。 */
    private const DEFAULT_TOKEN_LIFETIME_SECONDS = 5184000;

    private const MEDIA_FIELDS = 'id,caption,media_type,media_product_type,media_url,permalink,thumbnail_url,timestamp,like_count,comments_count';

    public function __construct(private HttpFactory $http) {}

    public function isConfigured(): bool
    {
        return filled(config('instagram_feed.facebook.app_id'))
            && filled(config('instagram_feed.facebook.app_secret'));
    }

    public function redirectUri(): string
    {
        return (string) config('instagram_feed.facebook.redirect_uri');
    }

    public function buildAuthorizeUrl(string $state): string
    {
        $config = $this->config();
        $configId = trim((string) config('instagram_feed.facebook.login_config_id', ''));

        $parameters = [
            'client_id' => $config['app_id'],
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'state' => $state,
        ];
        if ($configId !== '') {
            // Facebook Login for Business：权限由后台配置决定，不再传 scope。
            $parameters['config_id'] = $configId;
        } else {
            $parameters['scope'] = implode(',', (array) config('instagram_feed.facebook.scopes', []));
        }

        return self::DIALOG_HOST.'/'.$this->graphVersion().'/dialog/oauth?'.http_build_query($parameters);
    }

    /** 授权码换短期用户 token。 */
    public function exchangeCodeForToken(string $code): string
    {
        $config = $this->config();

        $response = $this->send(fn (): Response => $this->http
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->get($this->graphUrl('/oauth/access_token'), [
                'client_id' => $config['app_id'],
                'client_secret' => $config['app_secret'],
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ]), 'FACEBOOK_TOKEN_EXCHANGE_FAILED', '换取 Facebook 授权令牌失败。');

        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw new InstagramFeedException('FACEBOOK_TOKEN_EXCHANGE_FAILED', 'Facebook 未返回访问令牌。', 502);
        }

        return $accessToken;
    }

    /**
     * 短期用户 token 换 60 天长效用户 token。
     *
     * @return array{access_token: string, expires_at: CarbonImmutable}
     */
    public function exchangeForLongLivedUserToken(string $shortLivedToken): array
    {
        $config = $this->config();

        $response = $this->send(fn (): Response => $this->http
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->get($this->graphUrl('/oauth/access_token'), [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $config['app_id'],
                'client_secret' => $config['app_secret'],
                'fb_exchange_token' => $shortLivedToken,
            ]), 'FACEBOOK_LONG_LIVED_TOKEN_FAILED', '换取 Facebook 长效令牌失败。');

        $accessToken = $response->json('access_token');
        if (! is_string($accessToken) || $accessToken === '') {
            throw new InstagramFeedException('FACEBOOK_LONG_LIVED_TOKEN_FAILED', 'Facebook 未返回长效访问令牌。', 502);
        }
        $expiresIn = $response->json('expires_in');

        return [
            'access_token' => $accessToken,
            'expires_at' => CarbonImmutable::now()->addSeconds(
                is_numeric($expiresIn) ? (int) $expiresIn : self::DEFAULT_TOKEN_LIFETIME_SECONDS,
            ),
        ];
    }

    /**
     * 列出授权者能操作、且关联了 IG 专业账号的主页。
     *
     * 主页 access token 由长效用户 token 派生，不会过期，后续查媒体都用它。
     *
     * @return list<array{page_id: string, page_name: string, page_access_token: string, ig_user_id: string, ig_username: string|null, ig_avatar_url: string|null}>
     */
    public function listPagesWithInstagram(string $userAccessToken): array
    {
        $response = $this->send(fn (): Response => $this->http
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->get($this->graphUrl('/me/accounts'), [
                'fields' => 'id,name,access_token,instagram_business_account{id,username,profile_picture_url}',
                'limit' => 100,
                'access_token' => $userAccessToken,
            ]), 'FACEBOOK_PAGES_FETCH_FAILED', '读取 Facebook 主页列表失败。');

        $pages = $response->json('data');
        $options = [];

        foreach (is_array($pages) ? $pages : [] as $page) {
            if (! is_array($page)) {
                continue;
            }
            $pageId = (string) ($page['id'] ?? '');
            $pageToken = $page['access_token'] ?? null;
            if ($pageId === '' || ! is_string($pageToken) || $pageToken === '') {
                continue;
            }

            $instagram = is_array($page['instagram_business_account'] ?? null)
                ? $page['instagram_business_account']
                : $this->fetchLinkedInstagram($pageId, $pageToken);
            $igUserId = (string) ($instagram['id'] ?? '');
            if ($igUserId === '') {
                continue;
            }

            $options[] = [
                'page_id' => $pageId,
                'page_name' => is_string($page['name'] ?? null) && $page['name'] !== '' ? $page['name'] : $pageId,
                'page_access_token' => $pageToken,
                'ig_user_id' => $igUserId,
                'ig_username' => is_string($instagram['username'] ?? null) ? $instagram['username'] : null,
                'ig_avatar_url' => is_string($instagram['profile_picture_url'] ?? null) ? $instagram['profile_picture_url'] : null,
            ];
        }

        return $options;
    }

    /** @return array{ig_user_id: string, username: string, account_type: string|null, profile_picture_url: string|null} */
    public function fetchProfile(string $igUserId, string $accessToken): array
    {
        $response = $this->send(fn (): Response => $this->http
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->get($this->graphUrl('/'.$igUserId), [
                'fields' => 'id,username,profile_picture_url',
                'access_token' => $accessToken,
            ]), 'INSTAGRAM_PROFILE_FETCH_FAILED', '读取 Instagram 账号信息失败。');

        $username = $response->json('username');
        if (! is_string($username) || $username === '') {
            throw new InstagramFeedException('INSTAGRAM_PROFILE_INCOMPLETE', 'Instagram 账号信息不完整。', 502);
        }

        return [
            'ig_user_id' => is_string($response->json('id')) ? $response->json('id') : $igUserId,
            'username' => $username,
            'account_type' => 'BUSINESS',
            'profile_picture_url' => is_string($response->json('profile_picture_url')) ? $response->json('profile_picture_url') : null,
        ];
    }

    /**
     * 拉取账号媒体，按分页游标一直翻到底。
     *
     * @return list<array<string, mixed>>
     */
    public function fetchMedia(string $igUserId, string $accessToken, int $maxItems): array
    {
        $collected = [];
        $nextUrl = null;
        $pageSize = (int) config('instagram_feed.media.page_size', 50);
        // 游标重复说明分页出问题了，再翻下去就是死循环。
        $seenCursors = [];

        while (count($collected) < $maxItems) {
            $limit = min($pageSize, $maxItems - count($collected));
            $url = $nextUrl;
            $response = $this->send(fn (): Response => is_string($url)
                ? $this->http->acceptJson()->connectTimeout(5)->timeout(30)->get($url)
                : $this->http->acceptJson()->connectTimeout(5)->timeout(30)->get($this->graphUrl('/'.$igUserId.'/media'), [
                    'fields' => self::MEDIA_FIELDS,
                    'limit' => $limit,
                    'access_token' => $accessToken,
                ]), 'INSTAGRAM_MEDIA_FETCH_FAILED', '读取 Instagram 媒体失败。');

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

    /**
     * 少数情况下嵌套字段拿不到，退回单独查一次主页。
     *
     * @return array<string, mixed>
     */
    private function fetchLinkedInstagram(string $pageId, string $pageToken): array
    {
        try {
            $response = $this->http
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(15)
                ->get($this->graphUrl('/'.$pageId), [
                    'fields' => 'instagram_business_account',
                    'access_token' => $pageToken,
                ]);
        } catch (ConnectionException) {
            return [];
        }

        $instagram = $response->successful() ? $response->json('instagram_business_account') : null;

        return is_array($instagram) ? $instagram : [];
    }

    /** @return array{app_id: string, app_secret: string} */
    private function config(): array
    {
        $appId = trim((string) config('instagram_feed.facebook.app_id'));
        $appSecret = (string) config('instagram_feed.facebook.app_secret');
        if ($appId === '' || $appSecret === '') {
            throw new InstagramFeedException(
                'FACEBOOK_PROVIDER_NOT_CONFIGURED',
                'Facebook 主页授权尚未配置，请先补齐 Facebook 应用凭证。',
                503,
            );
        }

        return ['app_id' => $appId, 'app_secret' => $appSecret];
    }

    private function graphVersion(): string
    {
        return trim((string) config('instagram_feed.facebook.graph_version', 'v25.0'), '/');
    }

    private function graphUrl(string $path): string
    {
        return self::GRAPH_HOST.'/'.$this->graphVersion().$path;
    }

    /** @param callable(): Response $request */
    private function send(callable $request, string $errorCode, string $message): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new InstagramFeedException('FACEBOOK_API_TIMEOUT', 'Facebook 接口请求超时，请稍后重试。', 502);
        }

        if ($response->failed()) {
            throw new InstagramFeedException($errorCode, $message.MetaGraphErrorReader::suffix($response), 502);
        }

        return $response;
    }
}
