<?php

namespace App\Http\Controllers;

use App\Exceptions\StudentDiscountException;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountClaim;
use App\Services\StudentDiscount\StudentDiscountCampaignService;
use App\Services\StudentDiscount\StudentDiscountClaimBatchService;
use App\Services\StudentDiscount\StudentDiscountClaimQueryService;
use App\Services\StudentDiscount\StudentDiscountClaimService;
use App\Services\StudentDiscount\StudentDiscountEmailTemplateService;
use App\Services\StudentDiscount\StudentDiscountMailDeliveryService;
use App\Services\StudentDiscount\StudentDiscountUsageSyncService;
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
        private StudentDiscountClaimQueryService $claimQuery,
        private StudentDiscountClaimBatchService $claimBatches,
        private StudentDiscountUsageSyncService $usageSync,
        private StudentDiscountEmailTemplateService $emailTemplates,
        private StudentDiscountMailDeliveryService $mailDelivery,
    ) {}

    public function index(Request $request, Organization $organization, Store $store): Response
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.claim.read');
        $campaign = $this->campaigns->getOrCreate($organization, $store, $request->user());
        $canViewEvidence = $request->user()->hasPermission('student_discount.view_evidence', $organization, $store);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'voided'])],
            'source' => ['nullable', Rule::in(['education_email', 'student_id'])],
            'review_method' => ['nullable', Rule::in(['education_email', 'ai', 'manual', 'unreviewed'])],
            'email' => ['nullable', 'string', 'max:120'],
            'submitted_from' => ['nullable', 'date'],
            'submitted_to' => ['nullable', 'date', 'after_or_equal:submitted_from'],
            'usage_status' => ['nullable', Rule::in(['unused', 'partially_used', 'used_up', 'expired'])],
            'per_page' => ['nullable', Rule::in([20, 30, 50])],
        ]);
        $dashboard = $this->claimQuery->dashboard($organization, $store, $request->user(), $filters, $canViewEvidence);

        return Inertia::render('StudentDiscounts/Index', [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'store' => ['id' => $store->id, 'name' => $store->name, 'currency' => $store->currency],
            'campaign' => $campaign->only([
                'enabled', 'code_prefix', 'discount_type', 'discount_value', 'applies_to', 'target_ids',
                'combines_with_order_discounts', 'combines_with_product_discounts',
                'combines_with_shipping_discounts', 'usage_limit', 'validity_days', 'education_email_domains',
            ]),
            'emailTemplates' => $this->emailTemplates->configuration($campaign, $store),
            'claims' => $dashboard['claims'],
            'counts' => $dashboard['counts'],
            'filters' => $dashboard['filters'],
            'filterOptions' => $dashboard['filter_options'],
            'batchResult' => $request->session()->get('student_discount_batch_result'),
            'permissions' => [
                'viewEvidence' => $canViewEvidence,
                'approve' => $request->user()->hasPermission('student_discount.approve', $organization, $store),
                'reject' => $request->user()->hasPermission('student_discount.reject', $organization, $store),
                'deleteClaim' => $request->user()->hasPermission('student_discount.claim.delete', $organization, $store),
                'manageCampaign' => $request->user()->hasPermission('student_discount.campaign.manage', $organization, $store),
                'manageEmailTemplates' => $request->user()->hasPermission('student_discount.email_template.manage', $organization, $store),
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

    public function updateEmailTemplates(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.email_template.manage');
        $values = $request->validate($this->emailTemplateRules());
        $campaign = $this->campaigns->getOrCreate($organization, $store, $request->user());
        $this->emailTemplates->update($organization, $store, $campaign, $values, $request->user());

        return back()->with('success', '当前店铺的学生优惠邮件内容已保存。');
    }

    public function testEmailTemplate(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.email_template.manage');
        $values = $request->validate([
            ...$this->emailTemplateRules(),
            'type' => ['required', Rule::in(['approval', 'rejection'])],
            'email' => ['required', 'email:rfc', 'max:254'],
        ]);
        try {
            $this->mailDelivery->sendTest(
                $organization,
                $store,
                $request->user(),
                $values['type'],
                $values['email'],
                [
                    'branding' => $values['branding'] ?? [],
                    'approval' => $values['approval'],
                    'rejection' => $values['rejection'],
                ],
            );
        } catch (\RuntimeException) {
            return back()->with('error', '测试邮件发送失败，请检查系统邮件配置后重试。');
        }

        return back()->with('success', '测试邮件已发送。');
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
        $values = $request->validate(['reason' => ['required', 'string', 'max:1000', 'regex:/\S/u']]);
        try {
            $this->claims->reject($organization, $store, $claim, $request->user(), $values['reason']);
        } catch (StudentDiscountException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', '申请已拒绝，通知邮件正在发送。');
    }

    public function bulkApprove(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.approve');
        $values = $request->validate([
            'claim_ids' => ['required', 'array', 'min:1', 'max:'.StudentDiscountClaimBatchService::BATCH_LIMIT],
            'claim_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $result = $this->claimBatches->approve($organization, $store, $values['claim_ids'], $request->user());

        return back()
            ->with('student_discount_batch_result', $result)
            ->with($result['failed'] > 0 ? 'error' : 'success', $this->batchMessage('批准', $result));
    }

    public function bulkReject(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.reject');
        $values = $request->validate([
            'claim_ids' => ['required', 'array', 'min:1', 'max:'.StudentDiscountClaimBatchService::BATCH_LIMIT],
            'claim_ids.*' => ['required', 'uuid', 'distinct'],
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
        ]);
        $result = $this->claimBatches->reject($organization, $store, $values['claim_ids'], $request->user(), trim($values['reason']));

        return back()
            ->with('student_discount_batch_result', $result)
            ->with($result['failed'] > 0 ? 'error' : 'success', $this->batchMessage('拒绝', $result));
    }

    public function syncUsage(Request $request, Organization $organization, Store $store): RedirectResponse
    {
        $this->assertUserScope($request, $organization, $store, 'student_discount.claim.read');
        $values = $request->validate([
            'code_ids' => ['required', 'array', 'min:1', 'max:'.StudentDiscountUsageSyncService::BATCH_LIMIT],
            'code_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        $result = $this->usageSync->queue($organization, $store, $request->user(), $values['code_ids']);

        return back()->with('success', "已将 {$result['queued']} 个当前店铺优惠码加入使用情况刷新队列。");
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

    /** @return array<string, array<int, mixed>> */
    private function emailTemplateRules(): array
    {
        $urlRules = ['nullable', 'string', 'max:2048', 'url:http,https'];
        $blockRules = function (string $type) use ($urlRules): array {
            return [
                "{$type}.content_blocks" => ['nullable', 'array', 'min:1', 'max:20'],
                "{$type}.content_blocks.*" => ['required', 'array'],
                "{$type}.content_blocks.*.type" => ['required', Rule::in(['heading', 'paragraph', 'button', 'note', 'divider', 'spacer', 'discount_code'])],
                "{$type}.content_blocks.*.text" => ['nullable', 'string', 'max:5000'],
                "{$type}.content_blocks.*.align" => ['nullable', Rule::in(['left', 'center', 'right'])],
                "{$type}.content_blocks.*.font_size" => ['nullable', 'integer', 'between:10,48'],
                "{$type}.content_blocks.*.bold" => ['nullable', 'boolean'],
                "{$type}.content_blocks.*.italic" => ['nullable', 'boolean'],
                "{$type}.content_blocks.*.underline" => ['nullable', 'boolean'],
                "{$type}.content_blocks.*.color" => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                "{$type}.content_blocks.*.background_color" => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                "{$type}.content_blocks.*.url" => $urlRules,
                "{$type}.content_blocks.*.width" => ['nullable', Rule::in(['auto', 'full'])],
                "{$type}.content_blocks.*.spacing" => ['nullable', 'integer', 'between:8,64'],
            ];
        };

        return [
            'branding' => ['nullable', 'array'],
            'branding.logo_url' => $urlRules,
            'branding.primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'branding.shop_url' => $urlRules,
            'branding.support_email' => ['nullable', 'email:rfc', 'max:254'],
            'branding.support_url' => $urlRules,
            'branding.instagram_url' => $urlRules,
            'branding.facebook_url' => $urlRules,
            'branding.tiktok_url' => $urlRules,
            'branding.youtube_url' => $urlRules,
            'approval' => ['required', 'array'],
            'approval.subject' => ['required', 'string', 'max:180', 'not_regex:/[\r\n]/'],
            'approval.preheader' => ['nullable', 'string', 'max:240'],
            'approval.heading' => ['nullable', 'string', 'max:180'],
            'approval.body' => ['required', 'string', 'max:5000'],
            'approval.cta_label' => ['nullable', 'string', 'max:60'],
            'approval.footer_note' => ['nullable', 'string', 'max:500'],
            ...$blockRules('approval'),
            'rejection' => ['required', 'array'],
            'rejection.subject' => ['required', 'string', 'max:180', 'not_regex:/[\r\n]/'],
            'rejection.preheader' => ['nullable', 'string', 'max:240'],
            'rejection.heading' => ['nullable', 'string', 'max:180'],
            'rejection.body' => ['required', 'string', 'max:5000'],
            'rejection.cta_label' => ['nullable', 'string', 'max:60'],
            'rejection.footer_note' => ['nullable', 'string', 'max:500'],
            ...$blockRules('rejection'),
        ];
    }

    /** @param array<string, mixed> $result */
    private function batchMessage(string $action, array $result): string
    {
        return "批量{$action}完成：成功 {$result['succeeded']} 条，已处理 {$result['unchanged']} 条，失败 {$result['failed']} 条。";
    }
}
