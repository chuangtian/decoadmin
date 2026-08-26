<?php

namespace App\Console\Commands;

use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Store;
use App\Services\StudentDiscount\StudentDiscountAppRegistryService;
use Illuminate\Console\Command;

class ReconcileStudentDiscountInstallation extends Command
{
    protected $signature = 'student-discounts:reconcile-installation {shop : Exact Shopify myshopify domain}';

    protected $description = 'Reconcile one previously bootstrapped student discount installation into the App Center registry';

    public function handle(StudentDiscountAppRegistryService $registry): int
    {
        $shop = strtolower(trim((string) $this->argument('shop')));
        $store = Store::query()
            ->where('shopify_domain', $shop)
            ->where('status', 'active')
            ->whereHas('shopifyConnection', fn ($query) => $query->whereIn('status', ['connected', 'warning']))
            ->with(['shopifyConnection' => fn ($query) => $query->whereIn('status', ['connected', 'warning'])])
            ->sole();
        $bootstrapAudit = AuditLog::query()
            ->where('store_id', $store->id)
            ->where('action', 'student_discount_shopify_app_bootstrapped')
            ->latest('id')
            ->first();
        $externalInstallationId = data_get($bootstrapAudit?->metadata, 'app_installation_id');
        $grantedScopes = data_get($bootstrapAudit?->metadata, 'granted_scopes');

        if (! is_string($externalInstallationId) || $externalInstallationId === '' || ! is_array($grantedScopes)) {
            $this->error('未找到该店铺有效的学生优惠 bootstrap 记录。');

            return self::FAILURE;
        }

        $installation = $registry->synchronizeInstallation(
            $store,
            $store->shopifyConnection,
            'active',
            $grantedScopes,
            'student_discount_reconciliation',
            $externalInstallationId,
        );

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'action' => 'student_discount_shopify_app_reconciled',
            'subject_type' => AppInstallation::class,
            'subject_id' => $installation->id,
            'metadata' => [
                'scope' => 'store',
                'environment' => (string) config('student_discount.environment'),
                'source_audit_id' => $bootstrapAudit->id,
            ],
        ]);

        $this->info('学生优惠 App 安装记录已同步。');

        return self::SUCCESS;
    }
}
