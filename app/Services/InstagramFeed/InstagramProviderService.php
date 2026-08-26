<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\InstagramAccount;
use Throwable;

/**
 * 两条授权路线的统一入口。同步、发布、后台页面只跟这里打交道，
 * 不直接碰 InstagramApiClient / FacebookApiClient。
 */
class InstagramProviderService
{
    public function __construct(
        private InstagramApiClient $instagram,
        private FacebookApiClient $facebook,
    ) {}

    /** @return array{instagram_login: bool, facebook_login: bool} */
    public function configuredProviders(): array
    {
        return [
            'instagram_login' => $this->instagram->isConfigured(),
            'facebook_login' => $this->facebook->isConfigured(),
        ];
    }

    public function buildAuthorizeUrl(string $provider, string $state): string
    {
        return $provider === 'facebook_login'
            ? $this->facebook->buildAuthorizeUrl($state)
            : $this->instagram->buildAuthorizeUrl($state);
    }

    public function redirectUri(string $provider): string
    {
        return $provider === 'facebook_login'
            ? $this->facebook->redirectUri()
            : $this->instagram->redirectUri();
    }

    /**
     * 返回可用的 access token。
     *
     * - instagram_login：60 天长效 token，快到期时自动续期
     * - facebook_login：主页 token，由长效用户 token 派生，不会过期
     */
    public function ensureFreshToken(InstagramAccount $account): string
    {
        $token = (string) $account->access_token_encrypted;
        if ($token === '') {
            throw new InstagramFeedException(
                'INSTAGRAM_ACCOUNT_NOT_AUTHORIZED',
                '账号还没有可用的访问令牌，请重新授权。',
                409,
            );
        }

        if ($account->usesFacebookLogin() || ! $account->token_expires_at) {
            return $token;
        }

        $secondsLeft = now()->diffInSeconds($account->token_expires_at, false);
        $threshold = (int) config('instagram_feed.instagram.refresh_when_days_left', 10) * 86400;
        // 已经过期的没法续期（Meta 要求 token 未过期），交给调用方按失败处理。
        if ($secondsLeft > $threshold || $secondsLeft <= 0) {
            return $token;
        }

        try {
            $refreshed = $this->instagram->refreshLongLivedToken($token);
        } catch (Throwable) {
            // 续期失败不阻断同步，继续用旧 token 试一次。
            return $token;
        }

        $account->forceFill([
            'access_token_encrypted' => $refreshed['access_token'],
            'token_expires_at' => $refreshed['expires_at'],
            'last_refreshed_at' => now(),
        ])->save();

        return $refreshed['access_token'];
    }

    /** @return list<array<string, mixed>> */
    public function fetchAccountMedia(InstagramAccount $account, ?int $maxItems = null): array
    {
        $accessToken = $this->ensureFreshToken($account);
        $limit = $maxItems ?? (int) config('instagram_feed.media.fetch_all_items', 2000);

        if ($account->usesFacebookLogin()) {
            if (blank($account->ig_user_id)) {
                throw new InstagramFeedException(
                    'INSTAGRAM_ACCOUNT_NOT_SELECTED',
                    '还没有选择要连接的 Instagram 账号。',
                    409,
                );
            }

            return $this->facebook->fetchMedia((string) $account->ig_user_id, $accessToken, $limit);
        }

        return $this->instagram->fetchMedia($accessToken, $limit);
    }

    /**
     * 刷新账号资料（用户名、头像）。
     *
     * @return array{ig_user_id: string, username: string, account_type: string|null, profile_picture_url: string|null}
     */
    public function refreshAccountProfile(InstagramAccount $account): array
    {
        $accessToken = $this->ensureFreshToken($account);
        $profile = $account->usesFacebookLogin() && filled($account->ig_user_id)
            ? $this->facebook->fetchProfile((string) $account->ig_user_id, $accessToken)
            : $this->instagram->fetchProfile($accessToken);

        $account->forceFill([
            'username' => $profile['username'],
            'account_type' => $profile['account_type'],
            'profile_picture_url' => $profile['profile_picture_url'],
        ])->save();

        return $profile;
    }

    /**
     * 列出 Facebook 授权者可选的 Instagram 账号。
     *
     * 主页 token 绝不下发到前端，这里剥掉。
     *
     * @return list<array{page_id: string, page_name: string, ig_user_id: string, ig_username: string|null, ig_avatar_url: string|null}>
     */
    public function listSelectablePages(InstagramAccount $account): array
    {
        $userToken = (string) $account->fb_user_token_encrypted;
        if ($userToken === '') {
            throw new InstagramFeedException(
                'FACEBOOK_AUTHORIZATION_EXPIRED',
                'Facebook 授权已失效，请重新授权。',
                409,
            );
        }

        return array_map(
            fn (array $page): array => [
                'page_id' => $page['page_id'],
                'page_name' => $page['page_name'],
                'ig_user_id' => $page['ig_user_id'],
                'ig_username' => $page['ig_username'],
                'ig_avatar_url' => $page['ig_avatar_url'],
            ],
            $this->facebook->listPagesWithInstagram($userToken),
        );
    }

    /**
     * 取回带主页 token 的完整选项，仅供服务端写库使用。
     *
     * @return array{page_id: string, page_name: string, page_access_token: string, ig_user_id: string, ig_username: string|null, ig_avatar_url: string|null}
     */
    public function resolvePage(InstagramAccount $account, string $pageId): array
    {
        $userToken = (string) $account->fb_user_token_encrypted;
        if ($userToken === '') {
            throw new InstagramFeedException(
                'FACEBOOK_AUTHORIZATION_EXPIRED',
                'Facebook 授权已失效，请重新授权。',
                409,
            );
        }

        foreach ($this->facebook->listPagesWithInstagram($userToken) as $page) {
            if (hash_equals($page['page_id'], $pageId)) {
                return $page;
            }
        }

        throw new InstagramFeedException('FACEBOOK_PAGE_NOT_FOUND', '找不到这个主页，请重新授权。', 404);
    }
}
