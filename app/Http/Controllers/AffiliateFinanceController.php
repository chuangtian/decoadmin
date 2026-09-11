<?php

namespace App\Http\Controllers;

use App\Domain\ReferralAffiliate\Models\AffiliateReward;
use App\Domain\ReferralAffiliate\Services\AffiliateFinanceWorkspace;
use App\Domain\ReferralAffiliate\Services\AffiliateLedgerService;
use App\Domain\ReferralAffiliate\Services\AffiliateManualAttributionService;
use App\Domain\ReferralAffiliate\Services\AffiliatePayoutService;
use App\Domain\ReferralAffiliate\Services\AffiliateReconciliationService;
use App\Domain\ReferralAffiliate\Services\AffiliateReportService;
use App\Domain\ReferralAffiliate\Services\AffiliateShopGuard;
use App\Domain\ReferralAffiliate\Support\Money;
use App\Jobs\SyncAffiliateOrder;
use App\Jobs\SyncAffiliateReward;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class AffiliateFinanceController extends Controller
{
    public function index(Request $r, Organization $organization, Store $store, string $section, AffiliateFinanceWorkspace $workspace)
    {
        $v = $r->validate(['days' => ['nullable', 'integer', 'in:7,30,90'], 'status' => ['nullable', 'string', 'max:32', 'regex:/^[a-z_]+$/']]);

        return Inertia::render('Affiliate/Finance', $workspace->page($organization, $store, $r->user(), $section, $v['status'] ?? null, (int) ($v['days'] ?? 30)));
    }

    public function retryReward(Request $r, Organization $organization, Store $store, string $reward)
    {
        app(AffiliateShopGuard::class)->actor($organization, $store, $r->user(), 'affiliate.programs.manage');
        $record = AffiliateReward::query()->forOrganization($organization)->forStore($store)->where('public_id', $reward)->firstOrFail();
        abort_unless(in_array($record->status, ['pending', 'failed', 'revoke_pending'], true), 409);
        $record->update(['attempts' => 0, 'last_error' => null, 'last_synced_at' => null]);
        SyncAffiliateReward::dispatch($organization->id, $store->id, $record->id);
        AuditLog::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'user_id' => $r->user()->id, 'action' => 'affiliate_reward_retry', 'subject_type' => $record::class, 'subject_id' => $record->id]);

        return back()->with('success', '奖励同步已提交，仍会核验资格与等待期。');
    }

    public function attribute(Request $r, Organization $organization, Store $store, string $conversion)
    {
        $v = $r->validate(['membership' => ['required', 'string', 'size:26'], 'reason' => ['required', 'string', 'min:3', 'max:1000'], 'request_id' => ['required', 'uuid']]);
        app(AffiliateManualAttributionService::class)->assign($organization, $store, $r->user(), $conversion, $v['membership'], $v['reason'], $v['request_id']);

        return back()->with('success', '订单补归因及退款抵扣已记录。');
    }

    public function reconcile(Request $r, Organization $organization, Store $store)
    {
        app(AffiliateShopGuard::class)->actor($organization, $store, $r->user(), 'affiliate.conversions.override');
        $v = $r->validate(['order_id' => ['nullable', 'string', 'regex:/^(?:gid:\/\/shopify\/Order\/)?\d+$/']]);
        if (! empty($v['order_id'])) {
            SyncAffiliateOrder::dispatch($organization->id, $store->id, $v['order_id']);
        } else {
            app(AffiliateReconciliationService::class)->enqueueRecent($store);
        }

        return back()->with('success', '订单核对已提交。');
    }

    public function release(Request $r, Organization $organization, Store $store)
    {
        app(AffiliateShopGuard::class)->actor($organization, $store, $r->user(), 'affiliate.commissions.approve');
        app(AffiliateLedgerService::class)->release($store);

        return back()->with('success', '已释放到期且通过审核的佣金。');
    }

    public function adjust(Request $r, Organization $organization, Store $store, AffiliateLedgerService $service)
    {
        $v = $r->validate(['membership' => ['required', 'string', 'size:26'], 'amount' => ['required', 'string', 'regex:/^-?\d{1,9}(?:\.\d{1,3})?$/'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'], 'request_id' => ['required', 'uuid']]);
        $service->adjust($organization, $store, $r->user(), $v['membership'], Money::input($v['amount'], $store->currency, 'amount'), $v['reason'], $v['request_id']);

        return back()->with('success', '调整分录已追加。');
    }

    public function review(Request $r, Organization $organization, Store $store, string $flag, AffiliateLedgerService $service)
    {
        $v = $r->validate(['action' => ['required', 'in:approved,rejected,dismissed'], 'reason' => ['required', 'string', 'min:3', 'max:1000']]);
        $service->review($organization, $store, $r->user(), $flag, $v['action'], $v['reason']);

        return back()->with('success', '风险审核已记录。');
    }

    public function createPayout(Request $r, Organization $organization, Store $store, AffiliatePayoutService $service)
    {
        $v = $r->validate(['minimum' => ['required', 'string', 'regex:/^\d{1,9}(?:\.\d{1,3})?$/'], 'cutoff' => ['required', 'date', 'before_or_equal:now']]);
        $service->create($organization, $store, $r->user(), $store->currency, Money::input($v['minimum'], $store->currency, 'minimum'), CarbonImmutable::parse($v['cutoff']));

        return back()->with('success', '结算批次已创建，可用分录已占用。');
    }

    public function payoutTransition(Request $r, Organization $organization, Store $store, string $batch, AffiliatePayoutService $service)
    {
        $v = $r->validate(['action' => ['required', 'in:paid,cancelled,failed'], 'reference' => ['nullable', 'string', 'max:255'],
            'proof' => ['nullable', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:5120']]);
        app(AffiliateShopGuard::class)->actor($organization, $store, $r->user(), $v['action'] === 'paid' ? 'affiliate.payouts.confirm' : 'affiliate.payouts.create');
        $path = $r->hasFile('proof') ? $r->file('proof')->store('affiliate-proofs/'.$organization->id.'/'.$store->id, 'local') : null;
        try {
            $record = $service->transition($organization, $store, $r->user(), $batch, $v['action'], $v['reference'] ?? '', $path);
            if ($path && $record->proof_path !== $path) {
                Storage::disk('local')->delete($path);
            }
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }

        return back()->with('success', '结算状态已更新。');
    }

    public function proof(Request $r, Organization $organization, Store $store, string $batch, AffiliateFinanceWorkspace $workspace)
    {
        $record = $workspace->batch($organization, $store, $r->user(), $batch);
        abort_unless($record->proof_path, 404);

        return Storage::disk('local')->download($record->proof_path, 'payment-proof.'.pathinfo($record->proof_path, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff']);
    }

    public function reportCsv(Request $r, Organization $organization, Store $store)
    {
        app(AffiliateShopGuard::class)->actor($organization, $store, $r->user(), 'affiliate.reports.export');
        $v = $r->validate(['days' => ['nullable', 'integer', 'in:7,30,90']]);
        $metrics = app(AffiliateReportService::class)->metrics($organization, $store, $r->user(), (int) ($v['days'] ?? 30));
        AuditLog::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'user_id' => $r->user()->id, 'action' => 'affiliate_report_exported', 'metadata' => ['currency' => $store->currency]]);

        return response()->streamDownload(function () use ($metrics, $store) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['metric', 'value', 'currency'], escape: '');
            foreach ($metrics as $key => $value) {
                if (! is_array($value)) {
                    fputcsv($out, [$key, $value, $store->currency], escape: '');
                }
            }fclose($out);
        }, 'affiliate-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function payoutCsv(Request $r, Organization $organization, Store $store, string $batch, AffiliateFinanceWorkspace $workspace)
    {
        app(AffiliateShopGuard::class)->actor($organization, $store, $r->user(), 'affiliate.reports.export');
        $record = $workspace->batch($organization, $store, $r->user(), $batch);

        return response()->streamDownload(function () use ($record) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['batch', 'membership', 'promoter', 'email', 'currency', 'amount'], escape: '');
            $groups = $record->items()->with('membership.promoter')->orderBy('id')->get()->groupBy(fn ($item) => $item->membership->promoter_id);
            foreach ($groups as $items) {
                $promoter = $items->first()->membership->promoter;
                $cells = [$record->public_id, $items->map(fn ($item) => $item->membership->public_id)->implode(';'),
                    $promoter->display_name, $promoter->email_encrypted, $record->currency, Money::decimal((int) $items->sum('amount_minor'), $record->currency)];
                $cells = array_map(fn ($cell) => preg_match('/^[=+\-@\t\r]/', (string) $cell) ? "'".$cell : $cell, $cells);
                fputcsv($out, $cells, escape: '');
            }
            fclose($out);
        }, 'affiliate-payout-'.$record->public_id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
