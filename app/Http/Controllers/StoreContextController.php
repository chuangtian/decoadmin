<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StoreContextController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
        ]);

        $store = Store::query()->with('organization')->findOrFail($validated['store_id']);
        abort_unless($request->user()?->canAccessStore($store), 403);

        $request->session()->put([
            'current_organization_id' => $store->organization_id,
            'current_store_id' => $store->getKey(),
        ]);

        return back()->with('success', "Switched to {$store->name}.");
    }
}
