<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStoreNotificationSettingsRequest;
use App\Models\Store;
use App\Services\StoreNotificationSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreNotificationSettingsController extends Controller
{
    public function show(Request $request, Store $store, StoreNotificationSettingsService $settings): Response
    {
        $this->authorize('view', $store);
        $canUpdate = $request->user()->hasPermission('store.update', $store->organization, $store);

        return Inertia::render('Stores/NotificationSettings', [
            'store' => ['id' => $store->id, 'name' => $store->name, 'shopify_domain' => $store->shopify_domain],
            'settings' => $settings->forFrontend($store, $canUpdate), 'canUpdate' => $canUpdate,
        ]);
    }

    public function update(UpdateStoreNotificationSettingsRequest $request, Store $store, StoreNotificationSettingsService $settings): RedirectResponse
    {
        $this->authorize('update', $store);
        $settings->update($store, $request->validated(), $request->user());

        return back()->with('success', '店铺通知设置已保存。');
    }
}
