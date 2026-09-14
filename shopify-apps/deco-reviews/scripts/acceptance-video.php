<?php
// Synthetic video fixture only; does not publish, send email, or change Shopify data.
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('staging') || config('deco_reviews.environment') !== 'test'
    || config('deco_reviews.active.client_id') !== 'a755a5ea264486246fd8836dab3e004c'
    || rtrim(config('app.url'), '/') !== 'https://testadmin.decomkt.com') {
    throw new RuntimeException('Authorized staging test app only.');
}
$path = $argv[1] ?? '';
$expected = $argv[2] ?? '';
if (! preg_match('/^[a-f0-9]{64}$/', $expected) || ! is_file($path)
    || filesize($path) > 1000000 || ! hash_equals($expected, hash_file('sha256', $path))) {
    throw new RuntimeException('Exact small synthetic fixture required.');
}
$store = \App\Models\Store::whereKey(1)->where('shopify_domain', 'macfox-test-app.myshopify.com')->firstOrFail();
$product = \App\Models\Product::where('organization_id', $store->organization_id)->where('store_id', $store->id)
    ->where('shopify_product_id', '10264083103992')->firstOrFail();
$user = \App\Models\User::where('metadata->is_super_admin', true)->firstOrFail();
$service = app(\DecoReviews\Services\ReviewService::class);
$service->authorize($user, $store, true);
$review = $service->create($store, ['kind' => 'product', 'product_id' => $product->id,
    'author_name' => 'DEMO Video QA', 'rating' => 3, 'title' => 'DEMO - synthetic video acceptance',
    'body' => 'Synthetic color-bar video for macfox-test-app acceptance only. Not a real customer endorsement.'],
    [new \Illuminate\Http\UploadedFile($path, 'deco-reviews-qa.mp4', null, null, true)], $user);
echo json_encode(['id' => $review->id, 'uuid' => $review->uuid, 'status' => $review->status,
    'store_id' => $review->store_id, 'media_count' => $review->media()->count(),
    'publish_at' => $review->publish_at]).PHP_EOL;
