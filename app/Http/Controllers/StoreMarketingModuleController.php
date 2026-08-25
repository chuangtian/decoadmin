<?php

namespace App\Http\Controllers;

use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Store;
use App\Services\Marketing\MarketingModuleCatalog;
use App\Services\StoreBusinessCredentialService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreMarketingModuleController extends Controller
{
    public function show(
        Store $store,
        string $module,
        MarketingModuleCatalog $modules,
        StoreBusinessCredentialService $credentials,
    ): Response {
        $this->authorize('view', $store);
        $installation = $this->installation($store);
        $definition = $modules->findForInstallation($installation, $module);
        $credentialProvider = $definition['credential_provider'] ?? null;
        $credential = $credentialProvider
            ? collect($credentials->catalogForFrontend($store))->firstWhere('key', $credentialProvider)
            : null;

        return Inertia::render('Marketing/Module', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'shopify_domain' => $store->shopify_domain],
            'module' => $definition,
            'credential' => $credential,
        ]);
    }

    public function update(
        Request $request,
        Store $store,
        string $module,
        MarketingModuleCatalog $modules,
    ): RedirectResponse {
        $this->authorize('view', $store);
        abort_unless($request->user()->hasPermission('apps.configure', $store->organization, $store), 403);
        $installation = $this->installation($store);
        $definition = $modules->findForInstallation($installation, $module);
        $validated = $modules->validate($module, $request->all());
        $settings = $installation->settings ?? [];
        $moduleSettings = is_array($settings['module_settings'] ?? null) ? $settings['module_settings'] : [];
        $oldValues = is_array($moduleSettings[$module] ?? null) ? $moduleSettings[$module] : [];
        $moduleSettings[$module] = $validated;
        $settings['module_settings'] = $moduleSettings;
        $settings['modules'] = array_replace(
            array_fill_keys(array_keys(config('shopify.marketing_modules', [])), true),
            is_array($settings['modules'] ?? null) ? $settings['modules'] : [],
        );
        $installation->forceFill(['settings' => $settings])->save();

        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $request->user()->id,
            'action' => 'marketing_module_configured',
            'subject_type' => AppInstallation::class,
            'subject_id' => $installation->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'old_values' => $oldValues,
            'new_values' => $validated,
            'metadata' => ['module' => $module, 'module_name' => $definition['name']],
        ]);

        return back()->with('success', $definition['name'].'配置已保存。');
    }

    private function installation(Store $store): AppInstallation
    {
        $installation = $store->appInstallations()
            ->where('status', 'active')
            ->whereHas('app', fn (Builder $query) => $query->where('handle', config('shopify.app_handle')))
            ->first();

        abort_unless($installation, 403, '当前店铺尚未安装 Deco Marketing 应用。');

        return $installation;
    }
}
