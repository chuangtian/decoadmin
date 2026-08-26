<?php

namespace App\Http\Controllers;

use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\AppCenter\AppConfigurationCatalog;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationCenterController extends Controller
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentStore $currentStore,
    ) {}

    public function installations(Request $request): Response
    {
        $organization = $this->currentOrganization->require();
        $storeIds = $this->accessibleStoreIds($request->user(), $organization);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,pending,uninstalled,disabled'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? '');

        $installations = AppInstallation::query()
            ->whereIn('store_id', $storeIds)
            ->whereHas('store', fn (Builder $query) => $query->whereBelongsTo($organization))
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->whereHas('app', fn (Builder $query) => $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('handle', 'like', "%{$search}%"))
                    ->orWhereHas('store', fn (Builder $query) => $query
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('shopify_domain', 'like', "%{$search}%"));
            }))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->with(['app:id,name,handle,status', 'store:id,organization_id,name,shopify_domain,status,timezone', 'installedBy:id,name'])
            ->latest('installed_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AppInstallation $installation): array => [
                'id' => $installation->id,
                'status' => $installation->status,
                'app' => $installation->app ? [
                    'id' => $installation->app->id,
                    'name' => $installation->app->name,
                    'handle' => $installation->app->handle,
                ] : null,
                'store' => [
                    'id' => $installation->store->id,
                    'name' => $installation->store->name,
                    'shopify_domain' => $installation->store->shopify_domain,
                ],
                'installed_by' => $installation->installedBy?->name,
                'installed_at' => $installation->installed_at?->toIso8601String(),
                'uninstalled_at' => $installation->uninstalled_at?->toIso8601String(),
            ]);

        return Inertia::render('Apps/Installations', [
            'installations' => $installations,
            'filters' => ['search' => $search, 'status' => $status],
        ]);
    }

    public function configurations(Request $request, AppConfigurationCatalog $catalog): Response
    {
        $organization = $this->currentOrganization->require();
        $store = $this->currentStore->get();
        if (! $store) {
            return Inertia::render('Apps/Configurations', [
                'store' => null,
                'applications' => [],
            ]);
        }

        $this->authorize('view', $store);
        abort_unless($store->organization_id === $organization->id, 403);
        $store->loadMissing('organization:id,name');
        $installations = AppInstallation::query()
            ->where('store_id', $store->id)
            ->whereIn('status', ['active', 'pending', 'disabled'])
            ->whereHas('app', fn (Builder $query) => $query->where('status', 'active'))
            ->with([
                'app:id,organization_id,name,handle,status,description,settings',
                'store:id,organization_id,name,shopify_domain,status',
                'store.organization:id,name',
                'shopifyConnection:id,store_id,status',
            ])
            ->orderByRaw("case when status = 'active' then 0 else 1 end")
            ->latest('installed_at')
            ->get()
            ->sortBy(fn (AppInstallation $installation): string => mb_strtolower((string) $installation->app?->name))
            ->values();

        return Inertia::render('Apps/Configurations', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'shopify_domain' => $store->shopify_domain],
            'applications' => $catalog->forInstallations($installations, $request->user()),
        ]);
    }

    public function logs(Request $request): Response
    {
        $organization = $this->currentOrganization->require();
        $storeIds = $this->accessibleStoreIds($request->user(), $organization);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $search = trim((string) ($filters['search'] ?? ''));

        $logs = AuditLog::query()
            ->where('organization_id', $organization->id)
            ->where(fn (Builder $query) => $query->whereNull('store_id')->orWhereIn('store_id', $storeIds))
            ->where(function (Builder $query): void {
                $query->where('action', 'like', 'shopify_app_%')
                    ->orWhere('action', 'like', 'shopify_connection_%')
                    ->orWhere('action', 'like', 'marketing_module_%')
                    ->orWhere('action', 'like', 'store_business_credential_%')
                    ->orWhere('action', 'like', 'student_discount_%');
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
                'module' => data_get($log->metadata, 'module'),
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
            'marketing_module_configured' => '营销模块配置已更新',
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
        ][$action] ?? str($action)->replace('_', ' ')->headline()->toString();
    }
}
