<?php

/** Staging-only integration checks. Business fixtures roll back; no external mail or Shopify mutations. */
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\ReferralAffiliate\Models\AffiliateClick;
use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateNotificationIntent;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Services\AffiliateAccountingService;
use App\Domain\ReferralAffiliate\Services\AffiliateCatalogService;
use App\Domain\ReferralAffiliate\Services\AffiliateFinanceWorkspace;
use App\Domain\ReferralAffiliate\Services\AffiliateImportService;
use App\Domain\ReferralAffiliate\Services\AffiliateInvitationOrderReader;
use App\Domain\ReferralAffiliate\Services\AffiliateLedgerService;
use App\Domain\ReferralAffiliate\Services\AffiliateManagementService;
use App\Domain\ReferralAffiliate\Services\AffiliateManualAttributionService;
use App\Domain\ReferralAffiliate\Services\AffiliateMaterialService;
use App\Domain\ReferralAffiliate\Services\AffiliateNotificationService;
use App\Domain\ReferralAffiliate\Services\AffiliateOrderReader;
use App\Domain\ReferralAffiliate\Services\AffiliatePayoutService;
use App\Domain\ReferralAffiliate\Services\AffiliatePortalService;
use App\Domain\ReferralAffiliate\Services\AffiliatePostPurchaseService;
use App\Domain\ReferralAffiliate\Services\AffiliateReportService;
use App\Domain\ReferralAffiliate\Services\AffiliateRetentionService;
use App\Domain\ReferralAffiliate\Services\AffiliateShopGuard;
use App\Domain\ReferralAffiliate\Services\AffiliateTrackingStatistics;
use App\Domain\ReferralAffiliate\Services\AffiliateTrackingTokenService;
use App\Http\Controllers\AffiliateFinanceController;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

if (! $app->environment('staging') || rtrim((string) config('app.url'), '/') !== 'https://testadmin.decomkt.com') {
    throw new RuntimeException('Staging only.');
}
$store = Store::query()->where('shopify_domain', AffiliateShopGuard::TEST_SHOP)->firstOrFail();
$org = $store->organization;
$prior = AffiliateProgramMembership::query()->forStore($store)->whereNotNull('approved_by')->oldest()->firstOrFail();
$actor = User::query()->findOrFail($prior->approved_by);
app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.programs.manage');
Queue::fake();
Http::preventStrayRequests();
config(['mail.default' => 'array']);
$checks = [];
$files = [];
$assetPath = null;
$privateFiles = [];
$assert = function (bool $ok, string $name) use (&$checks) {
    if (! $ok) {
        throw new RuntimeException('Regression failed: '.$name);
    }$checks[$name] = true;
};
$management = app(AffiliateManagementService::class);
$programValues = ['name' => 'TEST acceptance rollback', 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 0, 'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false];
DB::beginTransaction();
try {
    $management->updateSettings($org, $store, $actor, ['affiliate_enabled' => true, 'customer_referral_enabled' => true]);
    $program = $management->createProgram($org, $store, $actor, $programValues);
    $management->transitionProgram($org, $store, $actor, $program->public_id, 'activate');
    $management->updateProgram($org, $store, $actor, $program->public_id, $programValues + ['starts_at' => null, 'ends_at' => null]);
    $product = app(AffiliateCatalogService::class)->scopedQuery('product', $org, $store)->whereNotNull('shopify_product_id')->firstOrFail();
    $productId = 'gid://shopify/Product/'.basename($product->shopify_product_id);
    $management->replaceRules($org, $store, $actor, $program->public_id, [['scope' => 'product', 'reference' => $productId, 'type' => 'percentage', 'basis_points' => 2000, 'exclude' => false, 'fixed_mode' => 'order']]);
    $promoter = $management->createPromoter($org, $store, $actor, ['display_name' => 'TEST acceptance owner', 'email' => 'acceptance-owner-20260908@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
    $member = $program->memberships()->where('promoter_id', $promoter->id)->firstOrFail();
    $management->transitionMembership($org, $store, $actor, $member->public_id, 'approve');
    $member->refresh();
    $management->updateMembership($org, $store, $actor, $member->public_id, ['tier_key' => 'test', 'labels' => ['acceptance'], 'admin_notes' => 'TEST ONLY', 'commission_override' => null]);
    $assert($member->fresh()->labels === ['acceptance'], 'plan_rules_approval_and_member_profile');

    $csv = tempnam(sys_get_temp_dir(), 'referral-csv-');
    $files[] = $csv;
    file_put_contents($csv, "name,email\nAcceptance Import,acceptance-import-20260908@example.invalid\n");
    $import = app(AffiliateImportService::class);
    $assert($import->import($org, $store, $actor, $program->public_id, $csv) === 1 && $import->import($org, $store, $actor, $program->public_id, $csv) === 0, 'csv_import_duplicate_safe');
    app(AffiliatePortalService::class)->apply($store, ['program' => $program->public_id, 'name' => 'TEST public applicant', 'email' => 'acceptance-public-20260908@example.invalid', 'terms' => true]);
    $assert($program->memberships()->where('status', 'pending')->count() === 2, 'public_application');

    $png = tempnam(sys_get_temp_dir(), 'referral-image-');
    $files[] = $png;
    file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl6uVMAAAAASUVORK5CYII='));
    $materials = app(AffiliateMaterialService::class);
    $materials->upload($org, $store, $actor, new UploadedFile($png, 'test.png', 'image/png', null, true), 'TEST acceptance material');
    $asset = DB::table('affiliate_assets')->where('store_id', $store->id)->where('title', 'TEST acceptance material')->latest('id')->first();
    $assetPath = $asset->path;
    $assert(Storage::disk('local')->exists($assetPath), 'private_material_upload');
    $materials->remove($org, $store, $actor, $asset->public_id);
    $assert(! Storage::disk('local')->exists($assetPath), 'private_material_delete');

    $notifications = app(AffiliateNotificationService::class);
    $notifications->save($org, $store, $actor, 'conversion.created', ['subject' => 'TEST acceptance notice', 'body' => 'Hello {name}: {program}', 'enabled' => true]);
    $click = AffiliateClick::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'link_id' => $member->link->id, 'visitor_token' => hash('sha256', 'acceptance-current'), 'occurred_at' => now()]);
    $order = ['id' => 'gid://shopify/Order/999900000001', 'name' => '#TEST-ROLLBACK', 'ordered_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'paid' => true, 'cancelled' => false, 'is_test' => true, 'currency' => $store->currency, 'customer_id' => 'gid://shopify/Customer/999900000001', 'discount_codes' => [], 'tracking_token' => app(AffiliateTrackingTokenService::class)->issue($member->link, $click), 'lines' => [['id' => 'gid://shopify/LineItem/999900000001', 'product_id' => $productId, 'quantity' => 2, 'base_minor' => 20000]], 'refunds' => []];
    $accounting = app(AffiliateAccountingService::class);
    $conversion = $accounting->reconcile($store, $order);
    $accounting->reconcile($store, $order);
    $assert($conversion->commission_minor === 4000 && $conversion->entries()->count() === 1, 'signed_attribution_product_rule_and_order_idempotency');
    $intent = AffiliateNotificationIntent::query()->where('membership_id', $member->id)->where('event_key', 'conversion.created')->sole();
    $notifications->send($intent->id);
    $notifications->send($intent->id);
    $assert($intent->fresh()->status === 'sent' && Mail::mailer('array')->getSymfonyTransport()->messages()->count() === 1, 'notification_memory_transport_only_once');

    $order['refunds'] = [['id' => 'gid://shopify/Refund/999900000001', 'created_at' => now()->toIso8601String(), 'lines' => [['line_id' => $order['lines'][0]['id'], 'quantity' => 1, 'base_minor' => 10000]]]];
    $accounting->reconcile($store, $order);
    $assert($conversion->fresh()->reversed_minor === 2000, 'partial_refund_integer_accounting');
    $newProgram = $management->createProgram($org, $store, $actor, array_replace($programValues, ['name' => 'TEST corrected ownership']));
    $management->transitionProgram($org, $store, $actor, $newProgram->public_id, 'activate');
    $newPromoter = $management->createPromoter($org, $store, $actor, ['display_name' => 'TEST corrected owner', 'email' => 'acceptance-corrected-20260908@example.invalid', 'type' => 'affiliate', 'program_public_id' => $newProgram->public_id]);
    $newMember = $newProgram->memberships()->where('promoter_id', $newPromoter->id)->firstOrFail();
    $management->transitionMembership($org, $store, $actor, $newMember->public_id, 'approve');
    $newMember->refresh();
    $fakeReader = new class extends AffiliateOrderReader
    {
        public array $snapshot;

        public function __construct() {}

        public function read(Store $store, string $orderId): array
        {
            return $this->snapshot;
        }
    };
    $fakeReader->snapshot = $order;
    $app->instance(AffiliateOrderReader::class, $fakeReader);
    $requestId = (string) Str::uuid();
    $manual = app(AffiliateManualAttributionService::class);
    $manual->assign($org, $store, $actor, $conversion->public_id, $newMember->public_id, 'TEST verified correction', $requestId);
    $manual->assign($org, $store, $actor, $conversion->public_id, $newMember->public_id, 'TEST verified correction', $requestId);
    $assert((int) AffiliateLedgerEntry::query()->where('membership_id', $member->id)->sum('amount_minor') === 0 && $conversion->fresh()->reversed_minor === 1000, 'attribution_correction_keeps_original_history');

    $ledger = app(AffiliateLedgerService::class);
    $ledger->release($store);
    $payout = app(AffiliatePayoutService::class);
    $batch = $payout->create($org, $store, $actor, $store->currency, 100, CarbonImmutable::now());
    $assert($batch->total_minor === 1000 && $batch->items()->count() === 1 && $batch->items()->first()->membership_id === $newMember->id, 'isolated_payout_reservation');
    $payout->transition($org, $store, $actor, $batch->public_id, 'cancelled');
    $batch = $payout->create($org, $store, $actor, $store->currency, 100, CarbonImmutable::now());
    $proofPath = 'affiliate-proofs/'.$org->id.'/'.$store->id.'/test-'.Str::random(12).'.png';
    $privateFiles[] = $proofPath;
    Storage::disk('local')->put($proofPath, file_get_contents($png));
    $payout->transition($org, $store, $actor, $batch->public_id, 'paid', '', $proofPath);
    $payout->transition($org, $store, $actor, $batch->public_id, 'paid');
    $assert((int) AffiliateLedgerEntry::query()->where('membership_id', $newMember->id)->sum('amount_minor') === 0, 'payout_cancel_and_paid_idempotency_no_transfer');
    $request = Request::create('/', 'GET', ['days' => 7]);
    $request->setUserResolver(fn () => $actor);
    $controller = app(AffiliateFinanceController::class);
    ob_start();
    $controller->proof($request, $org, $store, $batch->public_id, app(AffiliateFinanceWorkspace::class))->sendContent();
    $proofBytes = ob_get_clean();
    $assert($proofBytes === file_get_contents($png), 'private_payment_proof_download');
    ob_start();
    $controller->payoutCsv($request, $org, $store, $batch->public_id, app(AffiliateFinanceWorkspace::class))->sendContent();
    $payoutCsv = ob_get_clean();
    $assert(str_contains($payoutCsv, 'TEST corrected owner'), 'payout_csv');
    $order['refunds'][] = ['id' => 'gid://shopify/Refund/999900000002', 'created_at' => now()->toIso8601String(), 'lines' => [['line_id' => $order['lines'][0]['id'], 'quantity' => 1, 'base_minor' => 10000]]];
    $accounting->reconcile($store, $order);
    $assert((int) AffiliateLedgerEntry::query()->where('membership_id', $newMember->id)->sum('amount_minor') === -1000, 'refund_after_payout_negative_balance');
    $flag = $conversion->risks()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'rule' => 'order_velocity', 'status' => 'open', 'evidence' => ['synthetic' => true]]);
    $ledger->review($org, $store, $actor, $flag->public_id, 'approved', 'TEST review');
    $assert($conversion->fresh()->status === 'refunded', 'risk_review_preserves_refund');
    $adjustId = (string) Str::uuid();
    $first = $ledger->adjust($org, $store, $actor, $member->public_id, 250, 'TEST adjustment', $adjustId);
    $second = $ledger->adjust($org, $store, $actor, $member->public_id, 250, 'TEST adjustment', $adjustId);
    $assert($first->id === $second->id, 'manual_adjustment_idempotency');
    $ledger->release($store);
    $laterBatch = $payout->create($org, $store, $actor, $store->currency, 100, CarbonImmutable::now());
    $assert($laterBatch->items()->where('membership_id', $member->id)->sole()->amount_minor === 250, 'superseded_history_does_not_block_later_payout');
    $payout->transition($org, $store, $actor, $laterBatch->public_id, 'cancelled');
    $report = app(AffiliateReportService::class)->metrics($org, $store, $actor, 7);
    $assert(is_array($report['ranking']) && is_array($report['trend']) && is_numeric($report['net_sales']), 'report_window_ranking_and_trend');
    ob_start();
    $controller->reportCsv($request, $org, $store)->sendContent();
    $reportCsv = ob_get_clean();
    $assert(str_contains($reportCsv, 'net_sales'), 'report_csv');

    $auto = $management->createProgram($org, $store, $actor, array_replace($programValues, ['name' => 'TEST automatic invitation', 'type' => 'advocate', 'auto_invite' => true, 'reward' => ['type' => 'fixed', 'amount_minor' => 1000, 'valid_days' => 30, 'scope' => 'all', 'resource_ids' => []]]));
    $management->transitionProgram($org, $store, $actor, $auto->public_id, 'activate');
    $contactReader = new class extends AffiliateInvitationOrderReader
    {
        public function eligibleContact(Store $store, string $orderId): ?array
        {
            return ['customer_id' => 'gid://shopify/Customer/999900000002', 'email' => 'acceptance-auto-20260908@example.invalid', 'ordered_at' => now()->toIso8601String()];
        }
    };
    $app->instance(AffiliateInvitationOrderReader::class, $contactReader);
    $notifications->save($org, $store, $actor, 'customer.invited', ['subject' => 'TEST invitation', 'body' => 'Join {program}: {invitation_url}', 'enabled' => true]);
    $postPurchase = app(AffiliatePostPurchaseService::class);
    $postPurchase->prepare($org->id, $store->id, 'gid://shopify/Order/999900000002', $auto->id);
    $postPurchase->prepare($org->id, $store->id, 'gid://shopify/Order/999900000002', $auto->id);
    $assert($auto->memberships()->count() === 1, 'post_purchase_invitation_deduplicated');
    $autoMember = $auto->memberships()->sole();
    $autoIntent = AffiliateNotificationIntent::query()->where('membership_id', $autoMember->id)->where('event_key', 'customer.invited')->sole();
    $notifications->send($autoIntent->id);
    $assert($autoIntent->fresh()->status === 'sent', 'invitation_rendered_into_memory_transport');
    $assert(Mail::mailer('array')->getSymfonyTransport()->messages()->count() === 2, 'no_external_mail_transport');

    $beforeClicks = app(AffiliateTrackingStatistics::class)->count($store);
    $oldClick = AffiliateClick::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'link_id' => $member->link->id, 'visitor_token' => hash('sha256', 'acceptance-old'), 'occurred_at' => now()->subDays(190), 'ip_hash' => str_repeat('a', 64)]);
    $beforeLedger = AffiliateLedgerEntry::query()->forStore($store)->count();
    app(AffiliateRetentionService::class)->prune($store);
    $assert(! AffiliateClick::query()->find($oldClick->id) && app(AffiliateTrackingStatistics::class)->count($store) === $beforeClicks + 1 && AffiliateLedgerEntry::query()->forStore($store)->count() === $beforeLedger, 'retention_preserves_aggregates_and_ledger');
} finally {
    DB::rollBack();
    if ($assetPath && Storage::disk('local')->exists($assetPath)) {
        Storage::disk('local')->delete($assetPath);
    }
    foreach ($privateFiles as $privateFile) {
        Storage::disk('local')->delete($privateFile);
    }
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
echo json_encode(['shop' => $store->shopify_domain, 'checks' => $checks, 'business_fixtures_rolled_back' => true, 'real_transfer' => false, 'external_mail_sent' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE).PHP_EOL;
