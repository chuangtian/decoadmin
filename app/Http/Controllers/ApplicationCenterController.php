<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\AppCenter\AppConfigurationCatalog;
use App\Services\AppCenter\ApplicationCenterQueryService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationCenterController extends Controller
{
    public function __construct(private ApplicationCenterQueryService $applicationCenter) {}

    public function installations(): RedirectResponse
    {
        return redirect()->route('app-center.index');
    }

    public function configurations(
        Request $request,
        AppConfigurationCatalog $catalog,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): Response {
        $organization = $currentOrganization->require();
        $store = $currentStore->get();
        if (! $store) {
            return Inertia::render('Apps/Configurations', [
                'store' => null,
                'applications' => [],
            ]);
        }

        $this->authorize('view', $store);
        abort_unless($store->organization_id === $organization->id, 403);
        $store->loadMissing('organization:id,name');
        $installations = $this->applicationCenter->configurationInstallations($store);

        return Inertia::render('Apps/Configurations', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'shopify_domain' => $store->shopify_domain],
            'applications' => $catalog->forInstallations($installations, $request->user()),
        ]);
    }

    public function logs(Request $request, CurrentOrganization $currentOrganization): Response
    {
        $organization = $currentOrganization->require();
        $storeIds = $this->accessibleStoreIds($request->user(), $organization);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) ($filters['search'] ?? ''));

        $logs = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where(fn (Builder $query) => $query->whereNull('store_id')->orWhereIn('store_id', $storeIds))
            ->where(function (Builder $query): void {
                $query->where('action', 'like', 'shopify_app_%')
                    ->orWhere('action', 'like', 'shopify_connection_%')
                    ->orWhere('action', 'like', 'store_business_credential_%')
                    ->orWhere('action', 'like', 'student_discount_%')
                    ->orWhere('action', 'like', 'personalization_%')
                    ->orWhere('action', 'like', 'instagram_feed_%');
            })
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('action', 'like', "%{$search}%")
                    ->orWhereHas('store', fn (Builder $query) => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('shopify_domain', 'like', "%{$search}%"))
                    ->orWhereHas('user', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"));
            }))
            ->with(['store:id,organization_id,name,shopify_domain,timezone', 'user:id,name'])
            ->latest('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'uuid' => $log->uuid,
                'action' => $log->action,
                'action_label' => $this->actionLabel($log->action),
                'store' => $log->store ? ['id' => $log->store->id, 'name' => $log->store->name] : null,
                'actor' => $log->user?->name ?? '系统',
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Apps/Logs', [
            'logs' => $logs,
            'filters' => ['search' => $search],
        ]);
    }

    /** @return list<int> */
    private function accessibleStoreIds(User $user, Organization $organization): array
    {
        return $user->isSuperAdmin()
            ? $organization->stores()->pluck('id')->all()
            : $user->stores()->where('stores.organization_id', $organization->id)->pluck('stores.id')->all();
    }

    private function actionLabel(string $action): string
    {
        return [
            'shopify_app_uninstalled' => '应用已卸载',
            'shopify_connection_connected' => '应用连接成功',
            'shopify_connection_reconnected' => '应用重新连接',
            'shopify_connection_disconnected' => '应用连接已断开',
            'shopify_connection_invalid' => '应用授权失效',
            'store_business_credential_updated' => '应用凭证已更新',
            'store_business_credential_cleared' => '应用凭证已清除',
            'student_discount_shopify_app_bootstrapped' => '学生优惠应用连接成功',
            'student_discount_shopify_app_reconciled' => '学生优惠应用安装记录已同步',
            'student_discount_shopify_app_uninstalled' => '学生优惠应用已卸载',
            'student_discount_shopify_app_scopes_updated' => '学生优惠应用权限已更新',
            'student_discount_campaign_updated' => '学生优惠活动配置已更新',
            'student_discount_claim_submitted' => '学生优惠申请已提交',
            'student_discount_claim_resubmitted' => '学生优惠申请已重新提交',
            'student_discount_claim_approved' => '学生优惠申请已通过',
            'student_discount_claim_rejected' => '学生优惠申请已拒绝',
            'personalization_shopify_app_bootstrapped' => '个性化推荐应用连接成功',
            'personalization_shopify_app_uninstalled' => '个性化推荐应用已卸载',
            'personalization_shopify_app_scopes_updated' => '个性化推荐应用权限已更新',
            'personalization_strategy_created' => '个性化推荐策略已创建',
            'personalization_strategy_rules_replaced' => '个性化推荐规则已更新',
            'personalization_strategy_products_replaced' => '个性化推荐商品已更新',
            'personalization_strategy_updated' => '个性化推荐策略已更新',
            'personalization_component_created' => '个性化推荐组件已创建',
            'personalization_component_updated' => '个性化推荐组件已更新',
            'personalization_component_style_updated' => '个性化推荐样式已更新',
            'personalization_component_activated' => '个性化推荐组件已启用',
            'personalization_component_disabled' => '个性化推荐组件已停用',
            'personalization_smart_cart_draft_saved' => 'Smart Cart 草稿已保存',
            'personalization_smart_cart_compatibility_recorded' => 'Smart Cart 兼容性已检查',
            'personalization_smart_cart_preview_confirmed' => 'Smart Cart 预览已确认',
            'personalization_smart_cart_activated' => 'Smart Cart 已启用',
            'personalization_smart_cart_restored' => '已恢复 Shopify 默认购物车',
            'personalization_customer_data_request_received' => '已处理客户数据请求（无客户数据）',
            'personalization_customer_redact_received' => '已处理客户清除请求（无客户数据）',
            'personalization_shop_redact_received' => '已接收店铺清除请求',
            'personalization_online_data_purged' => '个性化推荐在线数据已清理',
            'instagram_feed_shopify_app_bootstrapped' => 'Instagram Feed 应用连接成功',
            'instagram_feed_shopify_app_reconciled' => 'Instagram Feed 应用安装记录已同步',
            'instagram_feed_shopify_app_uninstalled' => 'Instagram Feed 应用已卸载',
            'instagram_feed_shopify_app_scopes_updated' => 'Instagram Feed 应用权限已更新',
            'instagram_feed_account_connected' => 'Instagram 账号已连接',
            'instagram_feed_account_pending_page_selection' => 'Instagram 账号待选择主页',
            'instagram_feed_account_page_selected' => 'Instagram 主页已选择',
            'instagram_feed_account_disconnected' => 'Instagram 账号已断开',
            'instagram_feed_account_purged_by_meta' => 'Instagram 账号数据已按 Meta 要求清理',
            'instagram_feed_gallery_created' => 'Instagram 展示组已创建',
            'instagram_feed_gallery_renamed' => 'Instagram 展示组已重命名',
            'instagram_feed_gallery_deleted' => 'Instagram 展示组已删除',
            'instagram_feed_gallery_items_added' => 'Instagram 展示组已添加内容',
            'instagram_feed_gallery_items_removed' => 'Instagram 展示组已移除内容',
            'instagram_feed_gallery_reordered' => 'Instagram 展示组已重新排序',
            'instagram_feed_media_products_updated' => 'Instagram 内容关联商品已更新',
            'instagram_feed_published' => 'Instagram 内容已发布前台',
        ][$action] ?? str($action)->replace('_', ' ')->headline()->toString();
    }
}
