<?php

namespace App\Http\Controllers;

use App\Services\LiveViewService;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class LiveViewController extends Controller
{
    public function index(CurrentStore $currentStore, LiveViewService $liveView): Response
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);

        return Inertia::render('Analytics/Live', [
            'snapshot' => $liveView->snapshot($store),
        ]);
    }

    public function data(CurrentStore $currentStore, LiveViewService $liveView): JsonResponse
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);

        return response()->json($liveView->snapshot($store));
    }
}
