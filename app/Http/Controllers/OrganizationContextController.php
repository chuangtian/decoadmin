<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrganizationContextController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
        ]);
        $user = $request->user();
        $organization = Organization::query()
            ->where('status', 'active')
            ->findOrFail($validated['organization_id']);

        abort_unless($user && ($user->isSuperAdmin() || $user->organizations()->whereKey($organization->getKey())->exists()), 403);

        $store = $user->isSuperAdmin()
            ? $organization->stores()->orderBy('name')->first()
            : $user->stores()->where('stores.organization_id', $organization->getKey())->orderBy('name')->first();

        $request->session()->put('current_organization_id', $organization->getKey());

        if ($store) {
            $request->session()->put('current_store_id', $store->getKey());
        } else {
            $request->session()->forget('current_store_id');
        }

        return back()->with('success', "已切换至 {$organization->name}。");
    }
}
