<?php
// Explicit single-store acceptance harness. No persistent sending gate is enabled.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('staging') || config('deco_reviews.environment') !== 'test'
    || config('deco_reviews.active.client_id') !== 'a755a5ea264486246fd8836dab3e004c'
    || rtrim(config('app.url'), '/') !== 'https://testadmin.decomkt.com') {
    throw new RuntimeException('Only the authorized staging test app is allowed.');
}
$mode = $argv[1] ?? '';
$recipient = strtolower($argv[2] ?? '');
if (! filter_var($recipient, FILTER_VALIDATE_EMAIL) || ! in_array($mode, ['configure', 'run', 'reminder', 'status'], true)) {
    throw new RuntimeException('Use configure|run|reminder|status with the explicitly authorized recipient.');
}
$store = \App\Models\Store::where('id', 1)->where('shopify_domain', 'macfox-test-app.myshopify.com')->firstOrFail();
$reviews = app(\DecoReviews\Services\ReviewService::class);
$user = \App\Models\User::where('metadata->is_super_admin', true)->firstOrFail();
$reviews->authorize($user, $store, true);
if ($mode === 'configure') {
    $settings = $reviews->settings($store);
    $reviews->saveSettings($store, $user, array_replace($settings, [
        'invites_enabled' => true, 'auto_invites_enabled' => true, 'reminders_enabled' => true,
        'domestic_delay_days' => 0, 'international_delay_days' => 0, 'marketing_only' => false,
        'subject' => 'DEMO Deco Reviews — please review your test order',
        'email_body' => 'This is the authorized macfox-test-app acceptance test for {product}. Please submit a sample review. No payment was charged. All ratings are welcome.',
    ]));
    echo "Test store automation configured; global sending remains disabled.\n";
    exit;
}
$externalId = $argv[3] ?? '';
if (! ctype_digit($externalId)) { throw new RuntimeException('Exact test Shopify order ID required.'); }
$order = \App\Models\Order::where('organization_id', $store->organization_id)->where('store_id', $store->id)
    ->where('shopify_order_id', $externalId)->where('email', $recipient)->firstOrFail();
if (in_array($mode, ['run', 'reminder'], true)) {
    $remote = app(\DecoReviews\Services\ShopifyClient::class)->query($store,
        'query QATestOrderGuard($id: ID!) { order(id: $id) { test tags email totalPriceSet { shopMoney { amount } } } }', ['id' => 'gid://shopify/Order/'.$externalId]);
    $synthetic = ($remote['order']['test'] ?? null) === true || (in_array('DECO_REVIEWS_QA', $remote['order']['tags'] ?? [], true)
        && isset($remote['order']['totalPriceSet']['shopMoney']['amount']) && (float) $remote['order']['totalPriceSet']['shopMoney']['amount'] === 0.0);
    if (! $synthetic || strtolower($remote['order']['email'] ?? '') !== $recipient) {
        throw new RuntimeException('Only the exact synthetic Shopify order and recipient are allowed.');
    }
    config(['deco_reviews.automation_stores' => [$store->shopify_domain], 'deco_reviews.recipient_allowlist' => [$recipient], 'deco_reviews.delivery_enabled' => true]);
    if ($mode === 'run') {
        app(\DecoReviews\Services\InvitationService::class)->discover($store);
    } else {
        $invite = \DecoReviews\Models\Invitation::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->where('order_id', $order->id)->where('email_hash', $reviews->emailHash($store, $recipient))->sole();
        if ($invite->status !== 'sent' || ! $invite->sent_at || $invite->completed_at) {
            throw new RuntimeException('Only one sent and incomplete synthetic invitation is allowed.');
        }
        if (! $invite->reminder_sent_at) {
            $invite->forceFill(['sent_at' => now()->subDays($reviews->settings($store)['reminder_days'])->subMinute()])->save();
            $reviews->audit($store, $user, 'invitation.acceptance_reminder_due', null, ['invitation' => $invite->uuid]);
        }
    }
    $invites = \DecoReviews\Models\Invitation::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('order_id', $order->id)->get();
    foreach ($invites as $invite) { app(\DecoReviews\Services\InvitationProcessor::class)->process($store->organization_id, $store->id, $invite->uuid); }
}
echo json_encode(['store_id' => $store->id, 'order_id' => $order->id,
    'invitations' => \DecoReviews\Models\Invitation::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('order_id', $order->id)
        ->get(['uuid', 'status', 'sent_at', 'reminder_sent_at', 'completed_at', 'error_code'])->toArray()], JSON_UNESCAPED_SLASHES).PHP_EOL;
