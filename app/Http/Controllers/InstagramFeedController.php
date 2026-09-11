<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Store;
use App\Services\InstagramFeed\InstagramFeedPresenter;
use App\Services\InstagramFeed\InstagramFeedStoreCredentials;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Instagram Feed 的 DecoAdmin 后台视图，只读。
 *
 * 商家的全部操作都在 Shopify App 内嵌页完成（内容管理 + 应用配置），
 * 见 ShopifyInstagramFeedContentController。后台留这一页是给运营看运行状况用的：
 * Shopify 连接是否正常、素材转存到哪一步、有哪些展示组。
 *
 * 这里刻意不提供任何写操作 —— 同一份数据两个写入口会带来两套鉴权边界，
 * 而内嵌页那条链路（按 shop domain 判定店铺）已经覆盖了商家的所有需求。
 */
class InstagramFeedController extends Controller
{
    public function __construct(
        private InstagramFeedPresenter $presenter,
        private InstagramFeedStoreCredentials $credentials,
    ) {}

    public function index(Request $request, Organization $organization, Store $store): Response
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission('instagram_feed.view', $organization, $store), 403);

        // 「已配置 Meta / R2」这类就绪状态按店铺判断，所以先加载该店铺的生效凭证。
        $this->credentials->apply($store);

        return Inertia::render('InstagramFeed/Index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'store' => ['id' => $store->id, 'name' => $store->name, 'shopify_domain' => $store->shopify_domain],
            ...$this->presenter->overview($store),
            // 转存失败明细原本只有内嵌页能看，运营排查时也需要，所以只读页一并给出。
            'mirrorFailures' => $this->presenter->mirrorFailures($store, 20),
        ]);
    }
}
