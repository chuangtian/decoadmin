<?php

namespace App\Http\Controllers;

use App\Exceptions\StudentDiscountException;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountClaim;
use App\Services\StudentDiscount\StudentDiscountCampaignService;
use App\Services\StudentDiscount\StudentDiscountClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentDiscountController extends Controller
{
    public function __construct(
        private StudentDiscountCampaignService $campaigns,
        private StudentDiscountClaimService $claims,
    ) {}

    public function index(Request $request, Organization $organization, Store $store): Response
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.claim.read');
        $campaign = $this->campaigns->getOrCreate($organization, $store, $request->user());
        $canViewEvidence = $request->user()->hasPermission('student_discount.view_evidence', $organization, $store);
        $status = $request->string('status')->toString();
        $claims = StudentDiscountClaim::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true), fn ($query) => $query->where('status', $status))
            ->with(['reviewer:id,name', 'discountCode'])
            ->latest()
            ->paginate(30)
            ->withQueryString()
            ->through(fn (StudentDiscountClaim $claim): array => [
                'id' => $claim->uuid,
                'email' => $claim->email,
                'source' => $claim->source,
                'status' => $claim->status,
                'review_method' => $claim->review_method,
                'confidence' => $claim->confidence === null ? null : (float) $claim->confidence,
                'model_name' => $claim->model_name,
                'recognition_result' => $canViewEvidence ? $claim->recognition_result : null,
                'has_evidence' => filled($claim->evidence_path),
                'submission_count' => $claim->submission_count,
                'reviewer' => $claim->reviewer?->name,
                'rejection_reason' => $claim->rejection_reason,
                'created_at' => $claim->created_at->toIso8601String(),
                'reviewed_at' => $claim->reviewed_at?->toIso8601String(),
                'discount' => $claim->discountCode ? [
                    'code' => $claim->discountCode->code,
                    'status' => $claim->discountCode->refreshStatus()->status,
                    'usage_count' => $claim->discountCode->usage_count,
                    'usage_limit' => $claim->discountCode->usage_limit,
                    'expires_at' => $claim->discountCode->expires_at->toIso8601String(),
                ] : null,
            ]);

        $counts = StudentDiscountClaim::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return Inertia::render('StudentDiscounts/Index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency],
            'campaign' => $campaign->only([
                'enabled', 'code_prefix', 'discount_type', 'discount_value', 'applies_to', 'target_ids',
                'combines_with_order_discounts', 'combines_with_product_discounts',
                'combines_with_shipping_discounts', 'usage_limit', 'validity_days', 'education_email_domains',
            ]),
            'claims' => $claims,
            'counts' => ['pending' => (int) ($counts['pending'] ?? 0), 'approved' => (int) ($counts['approved'] ?? 0), 'rejected' => (int) ($counts['rejected'] ?? 0)],
            'filters' => ['status' => $status],
            'permissions' => [
                'viewEvidence' => $canViewEvidence,
                'approve' => $request->user()->hasPermission('student_discount.approve', $organization, $store),
                'reject' => $request->user()->hasPermission('student_discount.reject', $organization, $store),
                'deleteClaim' => $request->user()->hasPermission('student_discount.claim.delete', $organization, $store),
                'manageCampaign' => $request->user()->hasPermission('student_discount.campaign.manage', $organization, $store),
                'analytics' => $request->user()->hasPermission('student_discount.analytics.read', $organization, $store),
                'audit' => $request->user()->hasPermission('student_discount.audit.read', $organization, $store),
            ],
        ]);
    }

    public function updateCampaign(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.campaign.manage');
        $values = $request->validate([
            'enabled' => ['required', 'boolean'],
            'code_prefix' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9_-]+$/'],
            'discount_type' => ['required', Rule::in(['percentage', 'fixed_amount'])],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'applies_to' => ['required', Rule::in(['all', 'products', 'collections'])],
            'target_ids' => ['array', 'max:250'],
            'target_ids.*' => ['string', 'max:255', 'regex:/^gid:\/\/shopify\/(Product|Collection)\/\d+$/'],
            'combines_with_order_discounts' => ['required', 'boolean'],
            'combines_with_product_discounts' => ['required', 'boolean'],
            'combines_with_shipping_discounts' => ['required', 'boolean'],
            'usage_limit' => ['required', 'integer', 'between:1,100000'],
            'validity_days' => ['required', 'integer', 'between:1,365'],
            'education_email_domains' => ['array', 'max:500'],
            'education_email_domains.*' => ['string', 'max:253', 'regex:/^[A-Za-z0-9.-]+$/'],
        ]);
        if ($values['discount_type'] === 'percentage' && (float) $values['discount_value'] > 100) {
            throw ValidationException::withMessages(['discount_value' => '百分比优惠不能超过 100。']);
        }
        $this->campaigns->update($organization, $store, $values, $request->user());

        return back()->with('success', '学生优惠活动配置已保存。');
    }

    public function approve(Request $request, Organization $organization, Store $store, StudentDiscountClaim $claim): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.approve');
        try {
            $this->claims->approve($organization, $store, $claim, $request->user());
        } catch (StudentDiscountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', '申请已通过，优惠码已生成，通知邮件正在发送。');
    }

    public function reject(Request $request, Organization $organization, Store $store, StudentDiscountClaim $claim): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.reject');
        $values = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        try {
            $this->claims->reject($organization, $store, $claim, $request->user(), $values['reason']);
        } catch (StudentDiscountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', '申请已拒绝，通知邮件正在发送。');
    }

    public function destroy(Request $request, Organization $organization, Store $store, string $claim): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.claim.delete');
        try {
            $this->claims->deleteClaim($organization, $store, $claim, $request->user());
        } catch (StudentDiscountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', '申请记录及关联证件文件已删除。');
    }

    public function evidence(Request $request, Organization $organization, Store $store, StudentDiscountClaim $claim): StreamedResponse
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.view_evidence');
        abort_unless($claim->organization_id === $organization->id && $claim->store_id === $store->id, 404);
        abort_unless(filled($claim->evidence_path) && Storage::disk((string) $claim->evidence_disk)->exists((string) $claim->evidence_path), 404);

        return Storage::disk((string) $claim->evidence_disk)->download(
            (string) $claim->evidence_path,
            'student-evidence.'.match ($claim->evidence_mime) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            },
            ['Content-Type' => (string) $claim->evidence_mime, 'Cache-Control' => 'private, no-store'],
        );
    }

    private function assertUserScope(Request $request, Organization $organization, Store $store, string $permission): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission($permission, $organization, $store), 403);
    }
}
