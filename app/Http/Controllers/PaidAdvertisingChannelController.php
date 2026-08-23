<?php

namespace App\Http\Controllers;

use App\Services\Advertising\AdvertisingChannelStatusService;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class PaidAdvertisingChannelController extends Controller
{
    public function index(string $channel, CurrentStore $currentStore, AdvertisingChannelStatusService $status): Response
    {
        $store = $currentStore->require();
        $payload = $status->forStore($store, $channel);

        return Inertia::render('PaidAdvertising/Channel', [
            'store' => ['id' => $store->getKey(), 'name' => $store->name],
            'channelStatus' => $payload,
        ]);
    }

    public function status(string $channel, CurrentStore $currentStore, AdvertisingChannelStatusService $status): JsonResponse
    {
        return response()->json(['data' => $status->forStore($currentStore->require(), $channel)])
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }
}
