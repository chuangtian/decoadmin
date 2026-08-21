<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleAdsOAuthException;
use App\Services\GoogleAds\GoogleAdsOAuthService;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleAdsOAuthController extends Controller
{
    public function redirect(Request $request, CurrentStore $currentStore, GoogleAdsOAuthService $oauth): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);

        try {
            $authorization = $oauth->begin($store, $request->user());
        } catch (GoogleAdsOAuthException $exception) {
            return to_route('store-settings.credentials')->with('error', $exception->getMessage());
        }

        $request->session()->put(GoogleAdsOAuthService::SESSION_KEY, $authorization['session']);

        return redirect()->away($authorization['authorization_url']);
    }

    public function callback(Request $request, CurrentStore $currentStore, GoogleAdsOAuthService $oauth): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $stateSession = $request->session()->pull(GoogleAdsOAuthService::SESSION_KEY);

        try {
            $oauth->complete(
                $store,
                $request->user(),
                $request->query(),
                is_array($stateSession) ? $stateSession : null,
            );
        } catch (GoogleAdsOAuthException $exception) {
            return to_route('store-settings.credentials')->with('error', $exception->getMessage());
        }

        return to_route('store-settings.credentials')->with('success', 'Google Ads 已连接。');
    }
}
