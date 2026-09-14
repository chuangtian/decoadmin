<?php
// Local-only synthetic fixtures, never a Shopify or production write.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('local')) { throw new RuntimeException('Local-only fixture command.'); }
$store = \App\Models\Store::where('shopify_domain', 'macfox-test-app.myshopify.com')->firstOrFail();
$product = \App\Models\Product::where('organization_id', $store->organization_id)->where('store_id', $store->id)->firstOrFail();
$user = \App\Models\User::where('metadata->is_super_admin', true)->firstOrFail();
$service = app(\DecoReviews\Services\ReviewService::class);
foreach ([5, 4, 3, 2, 1] as $rating) {
    $review = $service->create($store, ['kind' => 'product', 'product_id' => $product->id, 'author_name' => 'DEMO Reviewer '.$rating,
        'author_email' => 'demo-'.$rating.'@example.test', 'rating' => $rating, 'title' => 'DEMO — '.$rating.' star preview',
        'body' => 'Synthetic acceptance fixture. This is not a customer review. Rating '.$rating.'.'], [], $user);
    $service->moderate($store, $user, [$review->uuid], ['status' => $rating > 1 ? 'published' : 'pending']);
}
echo 'Local synthetic preview fixtures ready.'.PHP_EOL;
