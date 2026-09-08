<?php

namespace App\Http\Controllers;

use App\Domain\ReferralAffiliate\Services\AffiliateManagementService;
use App\Domain\ReferralAffiliate\Services\AffiliateWorkspaceService;
use App\Models\Organization;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AffiliateController extends Controller
{
    public function __construct(private AffiliateWorkspaceService $workspace, private AffiliateManagementService $management) {}

    public function overview(Request $request, Organization $organization, Store $store): Response
    {
        return $this->index($request, $organization, $store, 'overview');
    }

    public function programs(Request $request, Organization $organization, Store $store): Response
    {
        return $this->index($request, $organization, $store, 'programs');
    }

    public function promoters(Request $request, Organization $organization, Store $store): Response
    {
        return $this->index($request, $organization, $store, 'promoters');
    }

    public function index(Request $request, Organization $organization, Store $store, string $section = 'overview'): Response
    {
        $this->assertScope($request, $organization, $store, $section === 'promoters' ? 'affiliate.promoters.view' : ($section === 'programs' ? 'affiliate.programs.view' : 'affiliate.dashboard.view'));

        return Inertia::render('Affiliate/Index', $this->workspace->dashboard($organization, $store, $request->user(), $section));
    }

    public function settings(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertScope($request, $organization, $store, 'affiliate.settings.manage');
        $values = $request->validate(['affiliate_enabled' => ['required', 'boolean'], 'customer_referral_enabled' => ['required', 'boolean']]);
        $this->management->updateSettings($organization, $store, $request->user(), $values);

        return back()->with('success', '推荐与联盟功能设置已保存。');
    }

    public function storeProgram(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertScope($request, $organization, $store, 'affiliate.programs.manage');
        $values = $request->validate([
            'name' => ['required', 'string', 'max:120', 'regex:/\S/u'],
            'type' => ['required', Rule::in(['affiliate', 'influencer', 'ambassador', 'advocate', 'partner'])],
            'attribution_model' => ['required', Rule::in(['coupon_wins', 'last_click', 'first_click'])],
            'attribution_window_days' => ['required', 'integer', 'between:1,90'],
            'hold_days' => ['required', 'integer', 'between:0,90'],
            'commission_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'rate_basis_points' => ['nullable', 'integer', 'between:1,10000', 'required_if:commission_type,percentage'],
            'amount_minor' => ['nullable', 'integer', 'between:1,1000000000', 'required_if:commission_type,fixed'],
        ]);
        $this->management->createProgram($organization, $store, $request->user(), $values);

        return back()->with('success', '推广计划已创建。');
    }

    public function storePromoter(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertScope($request, $organization, $store, 'affiliate.promoters.manage');
        $values = $request->validate([
            'display_name' => ['required', 'string', 'max:120', 'regex:/\S/u'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'type' => ['required', Rule::in(['affiliate', 'influencer', 'ambassador', 'advocate', 'partner'])],
            'program_public_id' => ['required', 'string', 'size:26'],
        ]);
        $this->management->createPromoter($organization, $store, $request->user(), $values);

        return back()->with('success', '推广者已加入计划，等待审核。');
    }

    private function assertScope(Request $request, Organization $organization, Store $store, string $permission): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission($permission, $organization, $store), 403);
    }
}
