<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Inertia\Inertia;
use Inertia\Response;

class StoreMarketingHomeController extends Controller
{
    public function __invoke(Store $store): Response
    {
        $this->authorize('view', $store);
        $installation = $store->appInstallations()
            ->where('status', 'active')
            ->whereHas('app', fn ($query) => $query->where('handle', config('shopify.app_handle')))
            ->first();

        abort_unless($installation, 403, '当前店铺尚未安装 Deco Marketing 应用。');

        $settings = $installation->settings ?? [];
        $moduleSettings = is_array($settings['modules'] ?? null) ? $settings['modules'] : [];
        $modules = collect(config('shopify.marketing_modules', []))
            ->map(fn (string $name, string $handle): array => [
                'handle' => $handle,
                'name' => $name,
                'enabled' => (bool) ($moduleSettings[$handle] ?? true),
            ])
            ->values();

        return Inertia::render('Marketing/Home', [
            'store' => [
                'id' => $store->getKey(),
                'name' => $store->name,
                'shopify_domain' => $store->shopify_domain,
            ],
            'modules' => $modules,
        ]);
    }
}
