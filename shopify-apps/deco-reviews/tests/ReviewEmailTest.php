<?php

namespace Tests\DecoReviews;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use DecoReviews\Models\EmailDelivery;
use DecoReviews\Models\Review;
use DecoReviews\Models\Settings;
use DecoReviews\Services\ReviewEmailDelivery;
use DecoReviews\Services\ReviewEmailService;
use DecoReviews\Services\ReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class ReviewEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        config(['deco_reviews' => require base_path('shopify-apps/deco-reviews/config/deco_reviews.php'), 'cache.default' => 'array']);
        Cache::flush();
        $this->user = User::factory()->create(['email_verified_at' => now(), 'metadata' => ['is_super_admin' => true]]);
        $this->organization = Organization::create(['name' => 'Review Email Test', 'code' => 'review-email-'.Str::lower(Str::random(6)), 'status' => 'active']);
        $this->organization->users()->attach($this->user, ['status' => 'active', 'joined_at' => now()]);
        $this->store = $this->organization->stores()->create(['name' => 'Safe Review Store', 'shopify_domain' => 'safe-review-email.myshopify.com', 'status' => 'active']);
        $this->store->members()->attach($this->user, ['status' => 'active', 'joined_at' => now()]);
        $this->product = Product::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'shopify_product_id' => '501', 'title' => 'Safe Bike', 'handle' => 'safe-bike', 'status' => 'active', 'synced_at' => now()]);
    }

    private function settings(array $replace = []): void
    {
        Settings::query()->updateOrCreate(['organization_id' => $this->organization->id, 'store_id' => $this->store->id],
            ['values' => array_replace(config('deco_reviews.defaults'), $replace)]);
    }

    private function createReview(array $replace = [], array $trusted = ['source' => 'organic', 'verified_source' => 'none']): Review
    {
        return app(ReviewService::class)->create($this->store, array_replace([
            'kind' => 'product', 'product_id' => $this->product->id, 'author_name' => 'Safe Buyer',
            'author_email' => 'safe-buyer@example.test', 'rating' => 5, 'title' => 'Safe review', 'body' => 'A synthetic review.',
        ], $replace), [], null, $trusted);
    }

    private function expectStatus(int $status, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected HTTP {$status}.");
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_product_thank_you_is_encrypted_idempotent_and_not_created_when_disabled_or_manual(): void
    {
        $this->settings();
        $this->createReview(['body' => 'Disabled lifecycle email.']);
        $this->assertDatabaseCount('deco_review_email_deliveries', 0);

        $this->settings(['product_thank_you_enabled' => true]);
        $review = $this->createReview(['body' => 'Enabled lifecycle email.']);
        $delivery = EmailDelivery::sole();
        $this->assertSame('product_thank_you', $delivery->type);
        $this->assertSame('scheduled', $delivery->status);
        $this->assertSame($review->id, $delivery->review_id);
        $this->assertSame('safe-buyer@example.test', $delivery->recipient);
        $this->assertNotSame('safe-buyer@example.test', DB::table('deco_review_email_deliveries')->whereKey($delivery->id)->value('recipient'));

        $this->createReview(['body' => 'Enabled lifecycle email.']);
        $this->assertDatabaseCount('deco_review_email_deliveries', 1);
        $this->createReview(['body' => 'Merchant-created review.'], []);
        $this->assertDatabaseCount('deco_review_email_deliveries', 1);
    }

    public function test_store_thank_you_and_reply_notification_have_separate_idempotency(): void
    {
        $this->settings(['store_thank_you_enabled' => true, 'reply_notification_enabled' => true]);
        $storeReview = $this->createReview(['kind' => 'store', 'product_id' => null, 'body' => 'Synthetic store review.']);
        $this->assertSame('store_thank_you', EmailDelivery::sole()->type);

        $productReview = $this->createReview(['body' => 'Synthetic reply review.']);
        $productReview->update(['status' => 'published', 'published_at' => now()]);
        app(ReviewService::class)->moderate($this->store, $this->user, [$productReview->uuid], ['reply' => 'A safe public reply.']);
        app(ReviewService::class)->moderate($this->store, $this->user, [$productReview->uuid], ['reply' => 'A revised safe public reply.']);
        $this->assertSame(1, EmailDelivery::where('review_id', $storeReview->id)->where('type', 'store_thank_you')->count());
        $this->assertSame(1, EmailDelivery::where('review_id', $productReview->id)->where('type', 'reply_notification')->count());
    }

    public function test_clearing_reply_or_disabling_message_cancels_only_scheduled_scoped_delivery(): void
    {
        $this->settings(['reply_notification_enabled' => true, 'product_thank_you_enabled' => true]);
        $review = $this->createReview(['body' => 'Cancellation test.']);
        $review->update(['status' => 'published', 'published_at' => now()]);
        app(ReviewService::class)->moderate($this->store, $this->user, [$review->uuid], ['reply' => 'Temporary reply.']);
        app(ReviewService::class)->moderate($this->store, $this->user, [$review->uuid], ['reply' => '']);
        $this->assertSame('cancelled', EmailDelivery::where('type', 'reply_notification')->sole()->status);

        app(ReviewService::class)->saveSettings($this->store, $this->user, array_replace(config('deco_reviews.defaults'), [
            'reply_notification_enabled' => true, 'product_thank_you_enabled' => false,
        ]));
        $this->assertSame('cancelled', EmailDelivery::where('type', 'product_thank_you')->sole()->status);
    }

    public function test_processing_is_store_scoped_sends_once_and_holds_uncertain_results(): void
    {
        $this->settings(['product_thank_you_enabled' => true]);
        $review = $this->createReview(['body' => 'Delivery success.']);
        $delivery = EmailDelivery::sole();
        config(['deco_reviews.automation_stores' => [$this->store->shopify_domain]]);
        $mock = Mockery::mock(ReviewEmailDelivery::class);
        $mock->shouldReceive('allowed')->once()->andReturnTrue();
        $mock->shouldReceive('send')->once()->andReturnTrue();
        app()->instance(ReviewEmailDelivery::class, $mock);
        app(ReviewEmailService::class)->process($this->organization->id, $this->store->id, $delivery->uuid);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->sent_at);
        app(ReviewEmailService::class)->process($this->organization->id, $this->store->id, $delivery->uuid);

        $heldReview = $this->createReview(['body' => 'Delivery uncertainty.']);
        $held = EmailDelivery::where('review_id', $heldReview->id)->sole();
        $mock = Mockery::mock(ReviewEmailDelivery::class);
        $mock->shouldReceive('allowed')->once()->andReturnTrue();
        $mock->shouldReceive('send')->once()->andReturnFalse();
        app()->instance(ReviewEmailDelivery::class, $mock);
        app(ReviewEmailService::class)->process($this->organization->id, $this->store->id, $held->uuid);
        $this->assertSame('held', $held->fresh()->status);
        $this->assertSame('DELIVERY_RESULT_REQUIRES_REVIEW', $held->fresh()->error_code);

        $this->expectStatus(404, fn () => app(ReviewEmailService::class)->schedule(
            $this->organization->stores()->create(['name' => 'Foreign Store', 'shopify_domain' => 'foreign-review-email.myshopify.com', 'status' => 'active']),
            $review, 'product_thank_you'));
    }

    public function test_all_lifecycle_email_previews_are_private_escaped_and_do_not_create_deliveries(): void
    {
        $this->settings([
            'product_thank_you_subject' => '<script>Product</script>', 'product_thank_you_body' => '<img src=x onerror=alert(1)>',
            'store_thank_you_subject' => '<script>Store</script>', 'reply_notification_subject' => '<script>Reply</script>',
        ]);
        $base = "/organizations/{$this->organization->id}/stores/{$this->store->id}/deco-reviews/email-preview";
        foreach (['product_thank_you' => 'Product', 'store_thank_you' => 'Store', 'reply_notification' => 'Reply'] as $kind => $marker) {
            $response = $this->actingAs($this->user)->get($base.'?kind='.$kind)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Content-Security-Policy');
            $this->assertStringContainsString('&lt;script&gt;'.$marker.'&lt;/script&gt;', $response->getContent());
            $this->assertStringNotContainsString('<script>'.$marker.'</script>', $response->getContent());
        }
        $this->assertDatabaseCount('deco_review_email_deliveries', 0);
    }
}
