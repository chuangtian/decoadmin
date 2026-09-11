<?php

namespace App\Http\Controllers;

use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Services\AffiliateCatalogService;
use App\Domain\ReferralAffiliate\Services\AffiliateCouponDispatchService;
use App\Domain\ReferralAffiliate\Services\AffiliateImportService;
use App\Domain\ReferralAffiliate\Services\AffiliateInvitationService;
use App\Domain\ReferralAffiliate\Services\AffiliateManagementService;
use App\Domain\ReferralAffiliate\Services\AffiliateShopGuard;
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
    public function invite(Request $request, Organization $organization, Store $store, string $membership)
    {
        $url = app(AffiliateInvitationService::class)->issue($organization, $store, $request->user(), $membership);

        return response()->json(['url' => $url])->header('Cache-Control', 'no-store');
    }

    public function syncCoupon(Request $request, Organization $organization, Store $store, string $membership): RedirectResponse
    {
        app(AffiliateShopGuard::class)->actor($organization, $store, $request->user(), 'affiliate.promoters.manage');
        $record = AffiliateProgramMembership::query()
            ->forOrganization($organization)->forStore($store)->where('public_id', $membership)->firstOrFail();
        app(AffiliateCouponDispatchService::class)->dispatch($store, membershipId: $record->id);

        return back()->with('success', '优惠码同步已提交，请稍后刷新查看结果。');
    }

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
        $values = $this->validateProgram($request);
        $this->management->createProgram($organization, $store, $request->user(), $values);

        return back()->with('success', '推广计划已创建。');
    }

    private function validateProgram(Request $request): array
    {
        return $request->validate([
            'auto_invite' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date'],
            'reward' => ['nullable', 'array'], 'milestones' => ['nullable', 'array', 'max:20'],
            'name' => ['required', 'string', 'max:120', 'regex:/\S/u'],
            'type' => ['required', Rule::in(['affiliate', 'influencer', 'ambassador', 'advocate', 'partner'])],
            'attribution_model' => ['required', Rule::in(['coupon_wins', 'last_click', 'first_click'])],
            'attribution_window_days' => ['required', 'integer', 'between:1,90'],
            'hold_days' => ['required', 'integer', 'between:0,90'],
            'commission_type' => ['required', Rule::in(['percentage', 'fixed'])],
            'rate_basis_points' => ['nullable', 'integer', 'between:1,10000', 'required_if:commission_type,percentage'],
            'amount_minor' => ['nullable', 'integer', 'between:1,1000000000', 'required_if:commission_type,fixed'],
            'coupon_enabled' => ['required', 'boolean'],
            'customer_discount_type' => ['nullable', Rule::in(['percentage', 'fixed', 'free_shipping']), 'required_if:coupon_enabled,true'],
            'customer_discount_rate_basis_points' => ['nullable', 'integer', 'between:1,10000', 'required_if:customer_discount_type,percentage'],
            'customer_discount_amount_minor' => ['nullable', 'integer', 'between:1,1000000000', 'required_if:customer_discount_type,fixed'],
        ]);
    }

    public function updateProgram(Request $request, Organization $organization, Store $store, string $program): RedirectResponse
    {
        $this->assertScope($request, $organization, $store, 'affiliate.programs.manage');
        $this->management->updateProgram($organization, $store, $request->user(), $program, $this->validateProgram($request));

        return back()->with('success', '计划已更新；已产生订单的佣金快照保持不变。');
    }

    public function rules(Request $request, Organization $organization, Store $store, string $program): RedirectResponse
    {
        $this->assertScope($request, $organization, $store, 'affiliate.programs.manage');
        $v = $request->validate(['rules' => ['present', 'array', 'max:100'], 'rules.*.scope' => ['required', 'in:variant,product,collection,tier'],
            'rules.*.reference' => ['required', 'string', 'max:255'], 'rules.*.type' => ['required', 'in:percentage,fixed'],
            'rules.*.basis_points' => ['nullable', 'integer', 'between:0,10000'], 'rules.*.amount_minor' => ['nullable', 'integer', 'between:0,1000000000'],
            'rules.*.exclude' => ['boolean'], 'rules.*.fixed_mode' => ['in:order,item']]);
        foreach ($v['rules'] as $rule) {
            abort_unless(isset($rule[$rule['type'] === 'percentage' ? 'basis_points' : 'amount_minor']), 422);
            if ($rule['scope'] !== 'tier') {
                abort_unless(preg_match('~^gid://shopify/(ProductVariant|Product|Collection)/[0-9]+$~D', $rule['reference']) === 1, 422);
            }
        }
        $this->management->replaceRules($organization, $store, $request->user(), $program, $v['rules']);

        return back()->with('success', '商品规则已保存。');
    }

    public function catalog(Request $request, Organization $organization, Store $store)
    {
        $v = $request->validate(['scope' => ['required', 'in:product,variant,collection'], 'q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => app(AffiliateCatalogService::class)->search($organization, $store, $request->user(), $v['scope'], $v['q'] ?? '')]);
    }

    public function updateMembership(Request $request, Organization $organization, Store $store, string $membership): RedirectResponse
    {
        $this->assertScope($request, $organization, $store, 'affiliate.promoters.manage');
        $values = $request->validate(['tier_key' => ['nullable', 'string', 'max:64'], 'admin_notes' => ['nullable', 'string', 'max:4000'],
            'labels' => ['present', 'array', 'max:20'], 'labels.*' => ['string', 'max:50'],
            'commission_override' => ['nullable', 'array:commission_type,rate_basis_points,amount_minor,settings'],
            'commission_override.commission_type' => ['required_with:commission_override', 'in:percentage,fixed'],
            'commission_override.rate_basis_points' => ['nullable', 'required_if:commission_override.commission_type,percentage', 'integer', 'between:0,10000'],
            'commission_override.amount_minor' => ['nullable', 'required_if:commission_override.commission_type,fixed', 'integer', 'between:0,1000000000'],
            'commission_override.settings' => ['nullable', 'array:fixed_mode'], 'commission_override.settings.fixed_mode' => ['in:order,item']]);
        $this->management->updateMembership($organization, $store, $request->user(), $membership, $values);

        return back()->with('success', '推广者资料已更新。');
    }

    public function importPromoters(Request $r, Organization $organization, Store $store): RedirectResponse
    {
        $v = $r->validate(['program' => ['required', 'string', 'size:26'], 'file' => ['required', 'file', 'max:1024']]);
        $count = app(AffiliateImportService::class)->import($organization, $store, $r->user(), $v['program'], $r->file('file')->getRealPath());

        return back()->with('success', '已新增 '.$count.' 位待审核推广者，重复记录已跳过。');
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

    public function transitionProgram(Request $request, Organization $organization, Store $store, string $program): RedirectResponse
    {
        $this->assertScope($request, $organization, $store, 'affiliate.programs.manage');
        $values = $request->validate(['action' => ['required', Rule::in(['activate', 'pause'])]]);
        $this->management->transitionProgram($organization, $store, $request->user(), $program, $values['action']);

        return back()->with('success', $values['action'] === 'activate' ? '推广计划已启用。' : '推广计划已暂停。');
    }

    public function transitionMembership(Request $request, Organization $organization, Store $store, string $membership): RedirectResponse
    {
        $this->assertScope($request, $organization, $store, 'affiliate.promoters.manage');
        $values = $request->validate(['action' => ['required', Rule::in(['approve', 'suspend', 'reject', 'waitlist'])], 'reason' => ['nullable', 'required_if:action,reject', 'string', 'min:3', 'max:1000']]);
        $this->management->transitionMembership($organization, $store, $request->user(), $membership, $values['action'], $values['reason'] ?? null);

        return back()->with('success', '推广者审核状态已更新。');
    }

    private function assertScope(Request $request, Organization $organization, Store $store, string $permission): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission($permission, $organization, $store), 403);
    }
}
