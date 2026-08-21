<?php

namespace App\Http\Controllers;

use App\Exceptions\YouTubeAnalyticsOAuthException;
use App\Services\YouTubeAnalytics\YouTubeAnalyticsOAuthService;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class YouTubeAnalyticsOAuthController extends Controller
{
    public function redirect(Request $request, CurrentStore $currentStore, YouTubeAnalyticsOAuthService $oauth): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);

        try {
            $authorization = $oauth->begin($store, $request->user());
        } catch (YouTubeAnalyticsOAuthException $exception) {
            return to_route('store-settings.credentials')->with('error', $exception->getMessage());
        }

        $request->session()->put(YouTubeAnalyticsOAuthService::SESSION_KEY, $authorization['session']);

        return redirect()->away($authorization['authorization_url']);
    }

    public function callback(Request $request, CurrentStore $currentStore, YouTubeAnalyticsOAuthService $oauth): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $stateSession = $request->session()->pull(YouTubeAnalyticsOAuthService::SESSION_KEY);

        try {
            $oauth->complete(
                $store,
                $request->user(),
                $request->query(),
                is_array($stateSession) ? $stateSession : null,
            );
        } catch (YouTubeAnalyticsOAuthException $exception) {
            return to_route('store-settings.credentials')->with('error', $exception->getMessage());
        }

        return to_route('store-settings.credentials')->with('success', 'YouTube Analytics 已连接。');
    }
}
