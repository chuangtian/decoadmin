<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\OAuthState;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Meta OAuth 的 state 管理。
 *
 * Meta 回调直接打到公开路由，请求里没有 DecoAdmin 会话，所以必须靠 state 还原
 * 「是哪个店铺在授权」。这里复用 DecoAdmin 已有的 oauth_states 表：state 明文只
 * 出现在授权链接里，库里只存 sha256，且带过期与一次性消费标记，比纯签名 state
 * 多了防重放能力。
 */
class MetaOAuthStateService
{
    private const PROVIDERS = ['instagram_login', 'facebook_login'];

    public function __construct(private InstagramFeedAppRegistry $registry) {}

    public function assertProvider(string $provider): string
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new InstagramFeedException('UNSUPPORTED_PROVIDER', '不支持的 Instagram 授权方式。', 422);
        }

        return $provider;
    }

    /** 生成一次性 state，返回放进授权链接的明文。 */
    public function issue(Store $store, User $actor, string $provider, string $redirectUri): string
    {
        $this->assertProvider($provider);
        $app = $this->registry->configuredApp();
        $plainState = Str::random(64);

        OAuthState::query()->create([
            'state_hash' => hash('sha256', $plainState),
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'app_id' => $app->id,
            'user_id' => $actor->getKey(),
            'shop_domain' => $store->shopify_domain,
            'redirect_uri' => $redirectUri,
            'intended_url' => null,
            'scopes' => null,
            'payload' => [
                'purpose' => 'instagram_feed_meta_oauth',
                'provider' => $provider,
                'environment' => $this->registry->environment(),
            ],
            'expires_at' => now()->addSeconds((int) config('instagram_feed.oauth_state_ttl_seconds', 900)),
        ]);

        return $plainState;
    }

    /**
     * 校验并消费 state，返回它绑定的店铺与授权方式。
     *
     * @return array{store: Store, provider: string, user: User|null}
     */
    public function consume(?string $plainState): array
    {
        if (! is_string($plainState) || $plainState === '' || mb_strlen($plainState) > 128) {
            throw new InstagramFeedException('INVALID_OAUTH_STATE', '授权链接已失效，请回到后台重新发起连接。', 400);
        }

        $state = OAuthState::query()
            ->with(['store.organization', 'user'])
            ->where('state_hash', hash('sha256', $plainState))
            ->first();
        if (! $state
            || data_get($state->payload, 'purpose') !== 'instagram_feed_meta_oauth'
            || data_get($state->payload, 'environment') !== $this->registry->environment()) {
            throw new InstagramFeedException('INVALID_OAUTH_STATE', '授权链接已失效，请回到后台重新发起连接。', 400);
        }

        $consumed = DB::transaction(function () use ($state): OAuthState {
            $locked = OAuthState::query()->lockForUpdate()->find($state->getKey());
            if (! $locked || $locked->consumed_at || $locked->expires_at->isPast()) {
                throw new InstagramFeedException('INVALID_OAUTH_STATE', '授权链接已失效，请回到后台重新发起连接。', 400);
            }

            $locked->forceFill(['consumed_at' => now()])->save();

            return $locked;
        });

        $store = $state->store;
        if (! $store || ! $store->organization || $store->status !== 'active' || $store->organization->status !== 'active') {
            throw new InstagramFeedException('STORE_NOT_AVAILABLE', '店铺当前不可用，无法完成授权。', 404);
        }

        return [
            'store' => $store,
            'provider' => (string) data_get($consumed->payload, 'provider'),
            'user' => $state->user,
        ];
    }
}
