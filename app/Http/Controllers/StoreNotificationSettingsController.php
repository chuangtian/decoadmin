<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStoreFeishuSettingsRequest;
use App\Http\Requests\UpdateStoreMailSettingsRequest;
use App\Http\Requests\UpdateStoreNotificationSettingsRequest;
use App\Models\Store;
use App\Services\StoreNotificationSettingsService;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreNotificationSettingsController extends Controller
{
    public function mail(Request $request, CurrentStore $currentStore, StoreNotificationSettingsService $settings): Response
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);
        $canUpdate = $request->user()->hasPermission('store.update', $store->organization, $store);

        return Inertia::render('Stores/Settings/Mail', [
            'store' => $this->storeSummary($store),
            'settings' => $settings->mailForFrontend($store, $canUpdate),
            'canUpdate' => $canUpdate,
        ]);
    }

    public function updateMail(UpdateStoreMailSettingsRequest $request, CurrentStore $currentStore, StoreNotificationSettingsService $settings): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $settings->updateMail($store, $request->validated(), $request->user());

        return back()->with('success', '店铺邮箱设置已保存。');
    }

    public function feishu(Request $request, CurrentStore $currentStore, StoreNotificationSettingsService $settings): Response
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);
        $canUpdate = $request->user()->hasPermission('store.update', $store->organization, $store);

        return Inertia::render('Stores/Settings/Feishu', [
            'store' => $this->storeSummary($store),
            'settings' => $settings->feishuForFrontend($store, $canUpdate),
            'canUpdate' => $canUpdate,
        ]);
    }

    public function updateFeishu(UpdateStoreFeishuSettingsRequest $request, CurrentStore $currentStore, StoreNotificationSettingsService $settings): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $settings->updateFeishu($store, $request->validated(), $request->user());

        return back()->with('success', '店铺飞书设置已保存。');
    }

    public function show(Store $store): RedirectResponse
    {
        $this->authorize('view', $store);

        return redirect()->route('store-settings.mail');
    }

    public function update(UpdateStoreNotificationSettingsRequest $request, Store $store, StoreNotificationSettingsService $settings): RedirectResponse
    {
        $this->authorize('update', $store);
        $settings->update($store, $request->validated(), $request->user());

        return back()->with('success', '店铺通知设置已保存。');
    }

    private function storeSummary(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'shopify_domain' => $store->shopify_domain,
        ];
    }
}
