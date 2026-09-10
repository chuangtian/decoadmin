<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\AuditLog;
use App\Models\InstagramAccount;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Instagram 账号连接的生命周期：发起授权、完成回调、选择主页、断开、按 Meta 用户清理。
 */
class InstagramAccountService
{
    public function __construct(
        private InstagramApiClient $instagram,
        private FacebookApiClient $facebook,
        private InstagramProviderService $providers,
        private MetaOAuthStateService $states,
        private InstagramMirrorService $mirror,
    ) {}

    /**
     * 生成授权链接。授权在新窗口完成，state 绑定当前店铺。
     *
     * actor 为空表示操作来自 Shopify 内嵌页面（店铺员工，没有 DecoAdmin 账号）。
     */
    public function authorizeUrl(Store $store, ?User $actor, string $provider): string
    {
        $this->states->assertProvider($provider);
        if (! data_get($this->providers->configuredProviders(), $provider)) {
            throw new InstagramFeedException(
                'PROVIDER_NOT_CONFIGURED',
                '该授权方式尚未配置，请先补齐 Meta 应用凭证。',
                503,
            );
        }

        $state = $this->states->issue($store, $actor, $provider, $this->providers->redirectUri($provider));

        return $this->providers->buildAuthorizeUrl($provider, $state);
    }

    /**
     * Instagram Login 回调：换长效 token、读账号资料、落库。
     *
     * @return array{account: InstagramAccount, needs_page_selection: false}
     */
    public function completeInstagramLogin(Store $store, ?User $actor, string $code): array
    {
        $shortLived = $this->instagram->exchangeCodeForToken($code);
        $longLived = $this->instagram->exchangeForLongLivedToken($shortLived['access_token']);
        $profile = $this->instagram->fetchProfile($longLived['access_token']);

        $account = $this->upsert($store, [
            'provider' => 'instagram_login',
            'status' => 'connected',
            'ig_user_id' => $profile['ig_user_id'],
            'username' => $profile['username'],
            'account_type' => $profile['account_type'],
            'profile_picture_url' => $profile['profile_picture_url'],
            'access_token_encrypted' => $longLived['access_token'],
            'token_expires_at' => $longLived['expires_at'],
            // 切换授权方式时清掉另一条路线的字段。
            'fb_user_id' => null,
            'fb_user_token_encrypted' => null,
            'fb_token_expires_at' => null,
            'page_id' => null,
            'page_name' => null,
            'last_refreshed_at' => now(),
        ], $actor);

        $this->audit($store, $account, $actor, 'instagram_feed_account_connected', [
            'provider' => 'instagram_login',
            'username' => $profile['username'],
        ]);

        return ['account' => $account, 'needs_page_selection' => false];
    }

    /**
     * Facebook Login 回调。
     *
     * 一个人可能管理多个主页：只有一个关联了 IG 的主页就直接连上；有多个则先存下
     * 长效用户 token，回后台让用户选。
     *
     * @return array{account: InstagramAccount, needs_page_selection: bool, page_count: int}
     */
    public function completeFacebookLogin(Store $store, ?User $actor, string $code): array
    {
        $shortLived = $this->facebook->exchangeCodeForToken($code);
        $longLived = $this->facebook->exchangeForLongLivedUserToken($shortLived);
        $pages = $this->facebook->listPagesWithInstagram($longLived['access_token']);

        if ($pages === []) {
            throw new InstagramFeedException(
                'FACEBOOK_NO_INSTAGRAM_ACCOUNT',
                '没找到可用的 Instagram 账号。请确认 Instagram 已切换为专业账号、已关联一个 Facebook 主页，并且你对该主页有管理权限。',
                422,
            );
        }

        $base = [
            'provider' => 'facebook_login',
            'fb_user_token_encrypted' => $longLived['access_token'],
            'fb_token_expires_at' => $longLived['expires_at'],
            'last_refreshed_at' => now(),
        ];

        if (count($pages) === 1) {
            $page = $pages[0];
            $account = $this->upsert($store, [
                ...$base,
                'status' => 'connected',
                'ig_user_id' => $page['ig_user_id'],
                'username' => $page['ig_username'] ?? $page['page_name'],
                'account_type' => 'BUSINESS',
                'profile_picture_url' => $page['ig_avatar_url'],
                'access_token_encrypted' => $page['page_access_token'],
                // 主页 token 不过期。
                'token_expires_at' => null,
                'page_id' => $page['page_id'],
                'page_name' => $page['page_name'],
            ], $actor);

            $this->audit($store, $account, $actor, 'instagram_feed_account_connected', [
                'provider' => 'facebook_login',
                'username' => $account->username,
                'page_id' => $page['page_id'],
            ]);

            return ['account' => $account, 'needs_page_selection' => false, 'page_count' => 1];
        }

        $account = $this->upsert($store, [
            ...$base,
            'status' => 'needs_page_selection',
            'access_token_encrypted' => null,
            'token_expires_at' => null,
            'ig_user_id' => null,
            'username' => null,
            'page_id' => null,
            'page_name' => null,
        ], $actor);

        $this->audit($store, $account, $actor, 'instagram_feed_account_pending_page_selection', [
            'provider' => 'facebook_login',
            'page_count' => count($pages),
        ]);

        return ['account' => $account, 'needs_page_selection' => true, 'page_count' => count($pages)];
    }

    /** 多主页场景下确认要连接的 Instagram 账号。actor 为空表示来自 Shopify 内嵌页面。 */
    public function selectPage(Store $store, ?User $actor, string $pageId): InstagramAccount
    {
        $account = $store->instagramAccount;
        if (! $account || ! $account->usesFacebookLogin()) {
            throw new InstagramFeedException('INSTAGRAM_ACCOUNT_NOT_CONNECTED', '当前店铺没有待确认的 Facebook 授权。', 409);
        }

        $page = $this->providers->resolvePage($account, $pageId);
        $account->forceFill([
            'status' => 'connected',
            'ig_user_id' => $page['ig_user_id'],
            'username' => $page['ig_username'] ?? $page['page_name'],
            'account_type' => 'BUSINESS',
            'profile_picture_url' => $page['ig_avatar_url'],
            'access_token_encrypted' => $page['page_access_token'],
            'token_expires_at' => null,
            'page_id' => $page['page_id'],
            'page_name' => $page['page_name'],
        ])->save();

        $this->audit($store, $account, $actor, 'instagram_feed_account_page_selected', [
            'page_id' => $page['page_id'],
            'username' => $account->username,
        ]);

        return $account;
    }

    /**
     * 断开授权：删账号记录，并连带清掉 R2 上的转存文件，否则对象会一直计费。
     *
     * @return array{records: int, objects: int, failed_keys: list<string>}
     */
    public function disconnect(Store $store, ?User $actor): array
    {
        $account = $store->instagramAccount;
        if (! $account) {
            throw new InstagramFeedException('INSTAGRAM_ACCOUNT_NOT_CONNECTED', '当前店铺还没有连接 Instagram 账号。', 409);
        }

        $purged = $this->mirror->purgeStoreMedia($store);
        $username = $account->username;
        $account->delete();
        $store->unsetRelation('instagramAccount');

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor?->getKey(),
            'action' => 'instagram_feed_account_disconnected',
            'subject_type' => Store::class,
            'subject_id' => $store->id,
            'metadata' => [
                'scope' => 'store',
                'environment' => (string) config('instagram_feed.environment'),
                'actor_type' => $actor ? 'user' : 'shopify_app_session',
                'shop_domain' => $store->shopify_domain,
                'username' => $username,
                'purged_records' => $purged['records'],
                'purged_objects' => $purged['objects'],
                'failed_object_deletes' => count($purged['failed_keys']),
            ],
        ]);

        return $purged;
    }

    /**
     * Meta 的解除授权 / 数据删除请求：按 Meta 用户 ID 找到对应店铺并清理干净。
     *
     * @return array{stores: int, records: int, objects: int}
     */
    public function purgeByMetaUser(string $metaUserId): array
    {
        $accounts = InstagramAccount::query()
            ->where(fn ($query) => $query->where('ig_user_id', $metaUserId)->orWhere('fb_user_id', $metaUserId))
            ->with('store')
            ->get();

        $stores = 0;
        $records = 0;
        $objects = 0;

        foreach ($accounts as $account) {
            $store = $account->store;
            if (! $store) {
                $account->delete();

                continue;
            }

            $purged = $this->mirror->purgeStoreMedia($store);
            $records += $purged['records'];
            $objects += $purged['objects'];
            $account->delete();
            $stores++;

            AuditLog::query()->create([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'action' => 'instagram_feed_account_purged_by_meta',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'metadata' => [
                    'scope' => 'store',
                    'environment' => (string) config('instagram_feed.environment'),
                    'purged_records' => $purged['records'],
                    'purged_objects' => $purged['objects'],
                ],
            ]);
        }

        return ['stores' => $stores, 'records' => $records, 'objects' => $objects];
    }

    /** @param array<string, mixed> $values */
    private function upsert(Store $store, array $values, ?User $actor): InstagramAccount
    {
        return DB::transaction(function () use ($store, $values, $actor): InstagramAccount {
            $account = InstagramAccount::query()
                ->where('store_id', $store->id)
                ->lockForUpdate()
                ->first() ?? new InstagramAccount;

            $account->fill([
                'organization_id' => $store->organization_id,
                'store_id' => $store->id,
                'connected_by' => $actor?->getKey() ?? $account->connected_by,
                ...$values,
            ]);
            $account->save();
            $store->setRelation('instagramAccount', $account);

            return $account;
        });
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Store $store, InstagramAccount $account, ?User $actor, string $action, array $metadata = []): void
    {
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => InstagramAccount::class,
            'subject_id' => $account->id,
            'metadata' => [
                'scope' => 'store',
                'environment' => (string) config('instagram_feed.environment'),
                // user_id 为空时要能看出是谁动的：Shopify 内嵌页面的操作者是店铺员工，
                // 只能按店铺追溯，所以把店铺域名一并记下来。
                'actor_type' => $actor ? 'user' : 'shopify_app_session',
                'shop_domain' => $store->shopify_domain,
                ...$metadata,
            ],
        ]);
    }
}
