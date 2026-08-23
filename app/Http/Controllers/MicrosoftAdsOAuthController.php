<?php

namespace App\Http\Controllers;

use App\Exceptions\MicrosoftAdsOAuthException;
use App\Services\Advertising\AdvertisingChannelLifecycleService;
use App\Services\MicrosoftAds\MicrosoftAdsOAuthService;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MicrosoftAdsOAuthController extends Controller
{
    public function redirect(Request $request, CurrentStore $currentStore, MicrosoftAdsOAuthService $oauth): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);

        try {
            $authorization = $oauth->begin($store, $request->user());
        } catch (MicrosoftAdsOAuthException $exception) {
            return to_route('store-settings.credentials')->with('error', $exception->getMessage());
        }

        $request->session()->put(MicrosoftAdsOAuthService::SESSION_KEY, $authorization['session']);

        return redirect()->away($authorization['authorization_url']);
    }

    public function callback(Request $request, CurrentStore $currentStore, MicrosoftAdsOAuthService $oauth, AdvertisingChannelLifecycleService $lifecycle): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $stateSession = $request->session()->pull(MicrosoftAdsOAuthService::SESSION_KEY);

        try {
            $oauth->complete(
                $store,
                $request->user(),
                $request->query(),
                is_array($stateSession) ? $stateSession : null,
            );
        } catch (MicrosoftAdsOAuthException $exception) {
            return to_route('store-settings.credentials')->with('error', $exception->getMessage());
        }

        $lifecycle->restartIfConfigured($store, 'bing_ads');

        return to_route('store-settings.credentials')->with('success', 'Microsoft Ads 已连接。');
    }
}
