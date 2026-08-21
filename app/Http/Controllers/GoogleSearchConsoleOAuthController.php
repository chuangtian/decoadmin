<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleSearchConsoleOAuthException;
use App\Services\GoogleSearchConsole\GoogleSearchConsoleOAuthService;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleSearchConsoleOAuthController extends Controller
{
    public function redirect(Request $request, CurrentStore $currentStore, GoogleSearchConsoleOAuthService $oauth): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);

        try {
            $authorization = $oauth->begin($store, $request->user());
        } catch (GoogleSearchConsoleOAuthException $exception) {
            return to_route('store-settings.credentials')->with('error', $exception->getMessage());
        }

        $request->session()->put(GoogleSearchConsoleOAuthService::SESSION_KEY, $authorization['session']);

        return redirect()->away($authorization['authorization_url']);
    }

    public function callback(Request $request, CurrentStore $currentStore, GoogleSearchConsoleOAuthService $oauth): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $stateSession = $request->session()->pull(GoogleSearchConsoleOAuthService::SESSION_KEY);

        try {
            $oauth->complete(
                $store,
                $request->user(),
                $request->query(),
                is_array($stateSession) ? $stateSession : null,
            );
        } catch (GoogleSearchConsoleOAuthException $exception) {
            return to_route('store-settings.credentials')->with('error', $exception->getMessage());
        }

        return to_route('store-settings.credentials')->with('success', 'Google Search Console 已连接。');
    }
}
