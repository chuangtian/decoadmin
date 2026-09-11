<?php

namespace App\Http\Controllers;

use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class DiscountManagerOAuthController extends Controller
{
    public function redirect(Request $request, CurrentOrganization $organizations, CurrentStore $stores): Response
    {
        $store = $stores->require();
        $organization = $organizations->require();
        $values = $request->validate(['store_id' => ['required', 'integer']]);
        abort_unless((int) $values['store_id'] === (int) $store->id, 409, '当前店铺已切换，请刷新后重试。');
        abort_unless((int) $store->organization_id === (int) $organization->id && $request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission('discounts.manage', $organization, $store), 403);
        $this->authorize('connect', $store);
        $domain = strtolower(trim((string) $store->shopify_domain));
        $clientId = trim((string) config('student_discount.active.client_id'));
        abort_unless(preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain) === 1 && $clientId !== '', 409);

        return Inertia::location("https://{$domain}/admin/apps/".rawurlencode($clientId));
    }
}
