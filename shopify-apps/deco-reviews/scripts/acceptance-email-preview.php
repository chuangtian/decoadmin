<?php
// One explicitly requested preview message; never creates an invitation or enables automation.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('staging') || config('deco_reviews.environment') !== 'test'
    || config('deco_reviews.active.client_id') !== 'a755a5ea264486246fd8836dab3e004c') {
    throw new RuntimeException('Staging test app only.');
}
$recipient = strtolower($argv[1] ?? '');
if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Explicit recipient required.'); }
$store = \App\Models\Store::whereKey(1)->where('shopify_domain', 'macfox-test-app.myshopify.com')->firstOrFail();
$user = \App\Models\User::where('metadata->is_super_admin', true)->firstOrFail();
$reviews = app(\DecoReviews\Services\ReviewService::class);
$reviews->authorize($user, $store, true);
app(\App\Services\SystemSettingsService::class)->applyRuntimeConfiguration();
if (config('mail.mailers.'.config('mail.default').'.transport') !== 'smtp') { throw new RuntimeException('SMTP required.'); }
$key = 'deco-reviews:qa-preview:'.$store->id.':'.hash('sha256', $recipient);
if (! \Illuminate\Support\Facades\Cache::add($key, 'attempted', now()->addDay())) {
    echo "Preview already attempted. Inspect inbox; do not retry an uncertain delivery.\n";
    exit;
}
$content = app(\DecoReviews\Services\InvitationEmail::class)->content($store);
$content['subject'] = 'DEMO Deco Reviews — email preview acceptance';
try {
    $sent = \Illuminate\Support\Facades\Mail::send('deco-reviews::emails.invitation', $content, function ($message) use ($recipient, $content, $store) {
        $message->to($recipient)->from(config('mail.from.address'), $store->name.' Reviews')->subject($content['subject']);
    });
    $reviews->audit($store, $user, $sent ? 'email.preview_sent' : 'email.preview_held');
    echo $sent ? "SMTP accepted one DEMO preview. Inbox verification still required.\n" : "Uncertain preview result; do not resend automatically.\n";
} catch (\Throwable) {
    $reviews->audit($store, $user, 'email.preview_held');
    echo "Preview delivery result requires inspection; no retry was made.\n";
}
