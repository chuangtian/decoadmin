<?php
// Explicitly authorized synthetic fixtures for staging macfox-test-app only.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('staging') || config('deco_reviews.environment') !== 'test'
    || config('deco_reviews.active.client_id') !== 'a755a5ea264486246fd8836dab3e004c'
    || rtrim(config('app.url'), '/') !== 'https://testadmin.decomkt.com') {
    throw new RuntimeException('Only the authorized staging test app is allowed.');
}
$store = \App\Models\Store::where('id', 1)->where('shopify_domain', 'macfox-test-app.myshopify.com')->firstOrFail();
$product = \App\Models\Product::where('organization_id', $store->organization_id)->where('store_id', $store->id)->orderBy('id')->firstOrFail();
$service = app(\DecoReviews\Services\ReviewService::class);
if (in_array('--prepare', $argv, true)) {
    $user = \App\Models\User::where('metadata->is_super_admin', true)->firstOrFail();
    $service->authorize($user, $store, true);
    foreach ([5, 4, 3, 2, 1] as $rating) {
        $review = $service->create($store, ['kind' => 'product', 'product_id' => $product->id,
            'author_name' => 'DEMO QA Reviewer '.$rating, 'author_email' => 'deco-reviews-qa-'.$rating.'@example.test',
            'rating' => $rating, 'title' => 'DEMO QA — '.$rating.' star acceptance',
            'body' => 'Synthetic Deco Reviews test fixture, not a customer review. Rating '.$rating.'.'], [], $user);
        $service->moderate($store, $user, [$review->uuid], ['status' => $rating === 1 ? 'pending' : 'published']);
    }
    $settings = $service->settings($store);
    $settings['enabled'] = true;
    $settings['invites_enabled'] = false;
    $service->saveSettings($store, $user, $settings);
}
$installation = \DecoReviews\Models\Installation::where('organization_id', $store->organization_id)
    ->where('store_id', $store->id)->where('environment', 'test')->first();
echo json_encode(['store_id' => $store->id, 'shop' => $store->shopify_domain,
    'product' => $product->only(['id', 'shopify_product_id', 'title', 'handle']),
    'installed' => $installation !== null && $installation->access_token !== null,
    'reviews' => \DecoReviews\Models\Review::where('store_id', $store->id)->count(),
    'published' => \DecoReviews\Models\Review::where('store_id', $store->id)->where('status', 'published')->count(),
    'form_question_count' => count(app(\DecoReviews\Services\FormService::class)->configuration($store)['questions']),
    'sending_enabled' => (bool) config('deco_reviews.delivery_enabled')], JSON_UNESCAPED_SLASHES).PHP_EOL;
