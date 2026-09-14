<?php

namespace App\Http\Controllers;

use App\Models\StoreAlert;
use App\Services\NotificationCenterService;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationCenterController extends Controller
{
    public function index(Request $request, CurrentStore $currentStore, NotificationCenterService $notifications): Response
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);

        return Inertia::render('Notifications/Index', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'notifications' => $notifications->paginate($store, $request->only(['status', 'type'])),
            'filters' => $request->only(['status', 'type']),
            'channels' => ['in_app' => true],
        ]);
    }

    public function resend(Request $request, StoreAlert $storeAlert, CurrentStore $currentStore, NotificationCenterService $notifications): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $notifications->resend($store, $storeAlert, $request->user());

        return back()->with('success', '通知已重新进入发送队列。');
    }
}
