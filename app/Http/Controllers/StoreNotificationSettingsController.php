<?php

namespace App\Http\Controllers;

use App\Http\Requests\ToggleStoreNotificationChannelRequest;
use App\Http\Requests\UpdateStoreFeishuDataLinksRequest;
use App\Http\Requests\UpdateStoreFeishuSettingsRequest;
use App\Http\Requests\UpdateStoreMailSettingsRequest;
use App\Http\Requests\UpdateStoreNotificationSettingsRequest;
use App\Models\Store;
use App\Services\StoreFeishuDataLinkService;
use App\Services\StoreNotificationSettingsService;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
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

    public function toggleMail(ToggleStoreNotificationChannelRequest $request, CurrentStore $currentStore, StoreNotificationSettingsService $settings): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $settings->toggleMail($store, $request->boolean('enabled'), $request->user());

        return back()->with('success', $request->boolean('enabled') ? '店铺邮箱已启用。' : '店铺邮箱已停用。');
    }

    public function feishu(
        Request $request,
        CurrentStore $currentStore,
        StoreNotificationSettingsService $settings,
        StoreFeishuDataLinkService $dataLinks,
    ): Response {
        $store = $currentStore->require();
        $this->authorize('view', $store);
        $canUpdate = $request->user()->hasPermission('store.update', $store->organization, $store);

        return Inertia::render('Stores/Settings/Feishu', [
            'store' => $this->storeSummary($store),
            'settings' => $settings->feishuForFrontend($store, $canUpdate),
            'tableSettings' => $settings->feishuTableForFrontend($store, $canUpdate),
            'dataLinks' => $dataLinks->catalogForFrontend($store, $canUpdate),
            'canUpdate' => $canUpdate,
        ]);
    }

    public function updateFeishuDataLinks(
        UpdateStoreFeishuDataLinksRequest $request,
        string $section,
        CurrentStore $currentStore,
        StoreFeishuDataLinkService $dataLinks,
    ): RedirectResponse {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $dataLinks->updateSection($store, $section, $request->validated('values'), $request->user());

        return back()->with('success', '飞书数据链接已保存。');
    }

    public function revealFeishuDataLink(
        string $section,
        string $field,
        CurrentStore $currentStore,
        StoreFeishuDataLinkService $dataLinks,
    ): JsonResponse {
        $store = $currentStore->require();
        $this->authorize('update', $store);

        return response()->json(['data' => ['value' => $dataLinks->reveal($store, $section, $field)]]);
    }

    public function updateFeishu(UpdateStoreFeishuSettingsRequest $request, CurrentStore $currentStore, StoreNotificationSettingsService $settings): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $settings->updateFeishu($store, $request->validated(), $request->user());

        return back()->with('success', '店铺飞书设置已保存。');
    }

    public function toggleFeishu(ToggleStoreNotificationChannelRequest $request, CurrentStore $currentStore, StoreNotificationSettingsService $settings): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $settings->toggleFeishu($store, $request->boolean('enabled'), $request->user());

        return back()->with('success', $request->boolean('enabled') ? '飞书机器人已启用。' : '飞书机器人已停用。');
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
