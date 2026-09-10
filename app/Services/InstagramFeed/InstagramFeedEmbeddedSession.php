<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use App\Models\InstagramFeedInstallation;
use App\Models\Store;
use Illuminate\Http\Request;

/**
 * Shopify 内嵌页面的请求上下文解析。
 *
 * 信任模型（与 DecoAdmin 后台不同，改动前请先读完）：
 *
 * - 身份只来自 App Bridge 的 session token，由 shopify.id-token:instagram_feed 中间件
 *   验签并把 dest 写进 shopify_shop 属性。浏览器传的 shop / store_id / organization_id
 *   一律不采信。
 * - 店铺由 shop domain 精确匹配 stores.shopify_domain 得到，所以一次请求只能触及
 *   这一个店铺的数据，跨店铺越权在解析这一步就被挡住。
 * - 内嵌环境里没有 DecoAdmin 用户，因此没有 instagram_feed.* 的按人 RBAC：
 *   凡是能在 Shopify 后台打开这个 App 的店铺员工，就能管理该店铺的 Instagram 内容。
 *   这是 Shopify App 的常规信任模型，审计记录里 user_id 为空、actor_type 记
 *   shopify_app_session、并附 shop_domain。
 * - 平台级配置（Meta 应用凭证、R2 存储凭证）不属于任何店铺，永远不从这条链路暴露，
 *   它们仍然只在 DecoAdmin 后台按 system.settings.* 权限管理。
 */
class InstagramFeedEmbeddedSession
{
    public function __construct(private ShopifyInstagramFeedAppService $app) {}

    /**
     * 解析当前请求对应的店铺，并保证本 App 的 Shopify 会话可用。
     *
     * 会话缺失时用当前 id_token 直接补一次 token exchange，所以商家不需要任何
     * “授权”动作：在 Shopify 后台打开应用就已经具备全部条件。
     */
    public function store(Request $request): Store
    {
        $shop = (string) $request->attributes->get('shopify_shop');
        $store = $this->app->connectedStore($shop);
        if (! $store) {
            throw new InstagramFeedException(
                'STORE_NOT_CONNECTED',
                '该 Shopify 店铺尚未在 DecoAdmin 登记，请先在后台添加对应店铺。',
                409,
            );
        }

        $installation = InstagramFeedInstallation::query()->where('store_id', $store->id)->first();
        if (! $installation?->isUsable()) {
            // 首次打开、令牌被清空、或环境切换后 environment 不再匹配，都走这里重建。
            $this->app->bootstrap($store, (string) $request->attributes->get('shopify_id_token'));
        }

        return $store;
    }

    /**
     * 内嵌会话的固定能力集。
     *
     * 这里不做按人授权，返回固定值只是让前端和 DecoAdmin 后台共用同一份渲染判断，
     * 真正的边界是「只能操作 store(Request) 解析出的那个店铺」。
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        return [
            'connect' => true,
            'sync' => true,
            'manageGallery' => true,
            'publish' => true,
        ];
    }
}
