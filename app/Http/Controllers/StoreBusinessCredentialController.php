<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStoreBusinessCredentialRequest;
use App\Models\Store;
use App\Services\StoreBusinessCredentialService;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreBusinessCredentialController extends Controller
{
    public function index(Request $request, CurrentStore $currentStore, StoreBusinessCredentialService $credentials): Response
    {
        $store = $currentStore->require();
        $this->authorize('view', $store);

        return Inertia::render('Stores/Settings/Credentials', [
            'store' => $this->storeSummary($store),
            'credentialProviders' => $credentials->catalogForFrontend($store),
            'canUpdate' => $request->user()->hasPermission('store.update', $store->organization, $store),
        ]);
    }

    public function reveal(string $provider, string $credentialKey, CurrentStore $currentStore, StoreBusinessCredentialService $credentials): JsonResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $credentials->fieldDefinition($provider, $credentialKey);

        return response()->json([
            'data' => ['value' => $credentials->reveal($store, $provider, $credentialKey)],
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function update(UpdateStoreBusinessCredentialRequest $request, string $provider, string $credentialKey, CurrentStore $currentStore, StoreBusinessCredentialService $credentials): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $definition = $credentials->fieldDefinition($provider, $credentialKey);
        $credentials->update($store, $provider, $credentialKey, $request->validated('value'), $request->user());

        return back()->with('success', $definition['label'].' 已保存。');
    }

    public function destroy(Request $request, string $provider, string $credentialKey, CurrentStore $currentStore, StoreBusinessCredentialService $credentials): RedirectResponse
    {
        $store = $currentStore->require();
        $this->authorize('update', $store);
        $definition = $credentials->fieldDefinition($provider, $credentialKey);
        $credentials->clear($store, $provider, $credentialKey, $request->user());

        return back()->with('success', $definition['label'].' 已清除。');
    }

    /** @return array{id: int, name: string, shopify_domain: string} */
    private function storeSummary(Store $store): array
    {
        return [
            'id' => $store->id,
            'name' => $store->name,
            'shopify_domain' => $store->shopify_domain,
        ];
    }
}
