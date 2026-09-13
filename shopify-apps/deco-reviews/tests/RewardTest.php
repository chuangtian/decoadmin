<?php

namespace Tests\DecoReviews;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use DecoReviews\Models\Installation;
use DecoReviews\Models\Media;
use DecoReviews\Models\Review;
use DecoReviews\Models\Reward;
use DecoReviews\Models\Settings;
use DecoReviews\Services\ReviewService;
use DecoReviews\Services\RewardService;
use DecoReviews\Services\ShopifyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class RewardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Store $store;

    private Product $product;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        config(['deco_reviews' => require base_path('shopify-apps/deco-reviews/config/deco_reviews.php'), 'cache.default' => 'array']);
        Cache::flush();
        $this->user = User::factory()->create(['email_verified_at' => now(), 'metadata' => ['is_super_admin' => true]]);
        $this->organization = Organization::create(['name' => 'Reward Test', 'code' => 'reward-'.Str::lower(Str::random(8)), 'status' => 'active']);
        $this->organization->users()->attach($this->user, ['status' => 'active', 'joined_at' => now()]);
        $this->store = $this->organization->stores()->create([
            'name' => 'Reward Store', 'shopify_domain' => 'reward-'.Str::lower(Str::random(8)).'.myshopify.com',
            'status' => 'active', 'currency' => 'USD',
        ]);
        $this->store->members()->attach($this->user, ['status' => 'active', 'joined_at' => now()]);
        $this->product = Product::create([
            'organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'shopify_product_id' => '901',
            'title' => 'Reward Bike', 'handle' => 'reward-bike', 'status' => 'active', 'synced_at' => now(),
        ]);
        $this->order = Order::create([
            'organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'shopify_order_id' => '9901',
            'order_number' => '#9901', 'email' => 'buyer@example.test', 'financial_status' => 'paid',
            'fulfillment_status' => 'fulfilled', 'currency' => 'USD', 'total_price' => 100, 'subtotal_price' => 100,
            'total_tax' => 0, 'processed_at' => now(), 'created_at_shopify' => now(), 'synced_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $this->order->id, 'shopify_line_item_id' => '19901', 'product_id' => $this->product->id,
            'shopify_product_id' => $this->product->shopify_product_id, 'title' => $this->product->title,
            'quantity' => 1, 'current_quantity' => 1, 'price' => 100,
        ]);
        $this->settings();
    }

    public function test_only_published_verified_email_media_reviews_are_scheduled_once(): void
    {
        $service = app(RewardService::class);
        $eligible = $this->review();
        $this->media($eligible, 'image');
        $reward = $service->schedule($this->store, $eligible);

        $this->assertNotNull($reward);
        $this->assertSame('photo', $reward->media_kind);
        $this->assertFalse($eligible->fresh()->incentivized);
        $this->assertSame($reward->id, $service->schedule($this->store, $eligible)?->id);
        $this->assertDatabaseCount('deco_review_rewards', 1);

        foreach ([
            ['status' => 'pending'],
            ['source' => 'organic', 'verified_source' => 'none', 'order_id' => null],
            ['verified_source' => 'none', 'order_id' => null],
            ['author_email' => null],
        ] as $index => $replace) {
            $review = $this->review($replace + ['body' => 'Ineligible '.$index]);
            $this->media($review, 'image');
            $this->assertNull($service->schedule($this->store, $review));
        }
        $withoutMedia = $this->review(['body' => 'No media']);
        $this->assertNull($service->schedule($this->store, $withoutMedia));
        $this->assertDatabaseCount('deco_review_rewards', 1);
    }

    public function test_settings_disable_only_the_affected_scheduled_rewards_and_enforce_store_currency(): void
    {
        $photo = $this->review(['body' => 'Photo']);
        $this->media($photo, 'image');
        $video = $this->review(['body' => 'Video']);
        $this->media($video, 'video');
        app(RewardService::class)->schedule($this->store, $photo);
        app(RewardService::class)->schedule($this->store, $video);

        app(ReviewService::class)->saveSettings($this->store, $this->user, $this->settingsInput([
            'photo_reward_enabled' => false, 'reward_discount_kind' => 'fixed', 'reward_value' => 12.5,
            'reward_currency' => 'EUR',
        ]));
        $this->assertSame('cancelled', Reward::where('review_id', $photo->id)->value('status'));
        $this->assertSame('scheduled', Reward::where('review_id', $video->id)->value('status'));
        $this->assertSame('USD', app(ReviewService::class)->settings($this->store)['reward_currency']);

        app(ReviewService::class)->saveSettings($this->store, $this->user, $this->settingsInput(['rewards_enabled' => false]));
        $this->assertSame('cancelled', Reward::where('review_id', $video->id)->value('status'));
    }

    public function test_successful_issue_is_idempotent_and_code_is_encrypted(): void
    {
        $review = $this->review();
        $this->media($review, 'image');
        $reward = app(RewardService::class)->schedule($this->store, $review);
        $this->enableWrites();
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldReceive('createReviewReward')->once()->withArgs(function (Store $store, string $kind, string $code, string $title, float $value, string $currency, $expiresAt) use ($reward) {
            return $store->is($this->store) && $kind === 'percentage' && str_starts_with($code, 'DECO-')
                && $title === 'Review reward '.$reward->uuid && $value === 10.0 && $currency === 'USD'
                && $expiresAt instanceof \DateTimeInterface;
        })->andReturn('gid://shopify/DiscountCodeNode/1');
        $this->app->instance(ShopifyClient::class, $client);

        app(RewardService::class)->process($this->organization->id, $this->store->id, $reward->uuid);
        app(RewardService::class)->process($this->organization->id, $this->store->id, $reward->uuid);

        $issued = $reward->fresh();
        $raw = DB::table('deco_review_rewards')->where('id', $reward->id)->first();
        $this->assertSame('issued', $issued->status);
        $this->assertTrue($review->fresh()->incentivized);
        $this->assertSame(1, $issued->attempts);
        $this->assertNotSame($issued->code, $raw->code);
        $this->assertSame(hash('sha256', $issued->code), $raw->code_hash);
    }

    public function test_uncertain_shopify_result_is_held_and_never_retried(): void
    {
        $review = $this->review();
        $this->media($review, 'image');
        $reward = app(RewardService::class)->schedule($this->store, $review);
        $this->enableWrites();
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldReceive('createReviewReward')->once()->andThrow(new \RuntimeException('timeout after request'));
        $this->app->instance(ShopifyClient::class, $client);

        app(RewardService::class)->process($this->organization->id, $this->store->id, $reward->uuid);
        app(RewardService::class)->process($this->organization->id, $this->store->id, $reward->uuid);

        $this->assertSame('held', $reward->fresh()->status);
        $this->assertSame('SHOPIFY_RESULT_REQUIRES_REVIEW', $reward->fresh()->error_code);
        $this->assertSame(1, $reward->fresh()->attempts);
    }

    public function test_master_write_gate_leaves_reward_scheduled_without_contacting_shopify(): void
    {
        $review = $this->review();
        $this->media($review, 'image');
        $reward = app(RewardService::class)->schedule($this->store, $review);
        config(['deco_reviews.automation_stores' => [$this->store->shopify_domain], 'deco_reviews.reward_writes_enabled' => false]);
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldNotReceive('createReviewReward');
        $this->app->instance(ShopifyClient::class, $client);

        app(RewardService::class)->process($this->organization->id, $this->store->id, $reward->uuid);

        $this->assertSame('scheduled', $reward->fresh()->status);
        $this->assertNull($reward->fresh()->code);
        $this->assertSame(0, $reward->fresh()->attempts);
    }

    public function test_stale_in_flight_reward_is_held_without_retry(): void
    {
        $review = $this->review();
        $this->media($review, 'image');
        $reward = app(RewardService::class)->schedule($this->store, $review);
        $this->enableWrites();
        DB::table('deco_review_rewards')->where('id', $reward->id)->update([
            'status' => 'issuing', 'attempts' => 1, 'updated_at' => now()->subMinutes(11),
        ]);
        $foreignOrganization = Organization::create(['name' => 'Foreign Reward', 'code' => 'foreign-reward-'.Str::lower(Str::random(6)), 'status' => 'active']);
        $foreignStore = $foreignOrganization->stores()->create(['name' => 'Foreign Reward', 'shopify_domain' => 'foreign-reward.myshopify.com', 'status' => 'active']);
        $foreignReview = Review::create(['uuid' => (string) Str::uuid(), 'organization_id' => $foreignOrganization->id, 'store_id' => $foreignStore->id,
            'kind' => 'store', 'author_name' => 'Foreign', 'rating' => 5, 'body' => 'Foreign', 'status' => 'published',
            'source' => 'email', 'verified_source' => 'none', 'reviewed_at' => now(), 'fingerprint' => hash('sha256', Str::uuid()->toString())]);
        $foreignReward = Reward::create(['uuid' => (string) Str::uuid(), 'organization_id' => $foreignOrganization->id, 'store_id' => $foreignStore->id,
            'review_id' => $foreignReview->id, 'media_kind' => 'photo', 'discount_kind' => 'percentage', 'value' => 10,
            'expiration_days' => 30, 'status' => 'issuing', 'attempts' => 1, 'updated_at' => now()->subMinutes(11)]);
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldNotReceive('createReviewReward');
        $this->app->instance(ShopifyClient::class, $client);

        $this->artisan('deco-reviews:dispatch-rewards')->assertSuccessful();

        $this->assertSame('held', $reward->fresh()->status);
        $this->assertNull($reward->fresh()->due_at);
        $this->assertSame('SHOPIFY_RESULT_REQUIRES_REVIEW', $reward->fresh()->error_code);
        $this->assertSame('issuing', $foreignReward->fresh()->status);
    }

    public function test_processing_rechecks_scope_and_current_publication_state(): void
    {
        $review = $this->review();
        $this->media($review, 'image');
        $reward = app(RewardService::class)->schedule($this->store, $review);
        $this->enableWrites();
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldNotReceive('createReviewReward');
        $this->app->instance(ShopifyClient::class, $client);

        app(RewardService::class)->process($this->organization->id + 999, $this->store->id, $reward->uuid);
        $this->assertSame('scheduled', $reward->fresh()->status);

        $review->update(['status' => 'unpublished', 'published_at' => null]);
        app(RewardService::class)->process($this->organization->id, $this->store->id, $reward->uuid);
        $this->assertSame('cancelled', $reward->fresh()->status);
    }

    public function test_history_is_store_scoped_and_never_returns_codes_or_shopify_ids(): void
    {
        $review = $this->review();
        $this->media($review, 'image');
        $reward = app(RewardService::class)->schedule($this->store, $review);
        $reward->update(['code' => 'DECO-SECRET', 'code_hash' => hash('sha256', 'DECO-SECRET'),
            'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/secret']);

        $history = app(RewardService::class)->history($this->store);
        $json = json_encode($history);
        $this->assertCount(1, $history);
        $this->assertStringNotContainsString('DECO-SECRET', $json);
        $this->assertStringNotContainsString('DiscountCodeNode', $json);
        $this->assertArrayNotHasKey('code', $history[0]);
        $this->assertArrayNotHasKey('code_hash', $history[0]);

        $foreignOrganization = Organization::create(['name' => 'Foreign', 'code' => 'foreign-'.Str::lower(Str::random(8)), 'status' => 'active']);
        $foreignStore = $foreignOrganization->stores()->create(['name' => 'Foreign', 'shopify_domain' => 'foreign-'.Str::lower(Str::random(8)).'.myshopify.com', 'status' => 'active']);
        try {
            app(RewardService::class)->schedule($foreignStore, $review);
            $this->fail('Expected cross-tenant scheduling to be rejected.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }

    public function test_shopify_client_builds_supported_variable_shapes_for_all_reward_types(): void
    {
        $this->enableWrites();
        Installation::create([
            'organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'environment' => config('deco_reviews.environment'), 'client_id' => config('deco_reviews.active.client_id'),
            'access_token' => 'shopify-test-token',
        ]);
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request->data();
            $key = str_contains((string) $request['query'], 'discountCodeFreeShippingCreate')
                ? 'discountCodeFreeShippingCreate' : 'discountCodeBasicCreate';

            return Http::response(['data' => [$key => ['codeDiscountNode' => ['id' => 'gid://shopify/DiscountCodeNode/'.count($requests)], 'userErrors' => []]]]);
        });
        $client = app(ShopifyClient::class);
        $expires = now()->addDays(30);

        $client->createReviewReward($this->store, 'percentage', 'DECO-PERCENT', 'Percent', 15, 'USD', $expires);
        $client->createReviewReward($this->store, 'fixed', 'DECO-FIXED', 'Fixed', 12.5, 'USD', $expires);
        $client->createReviewReward($this->store, 'free_shipping', 'DECO-SHIP', 'Shipping', 0, 'USD', $expires);

        $this->assertCount(3, $requests);
        $percentage = $requests[0]['variables']['basicCodeDiscount'];
        $this->assertSame(['all' => true], $percentage['context']);
        $this->assertSame(1, $percentage['usageLimit']);
        $this->assertTrue($percentage['appliesOncePerCustomer']);
        $this->assertSame(0.15, $percentage['customerGets']['value']['percentage']);
        $this->assertSame(['all' => true], $percentage['customerGets']['items']);
        $this->assertSame('12.50', $requests[1]['variables']['basicCodeDiscount']['customerGets']['value']['discountAmount']['amount']);
        $this->assertFalse($requests[1]['variables']['basicCodeDiscount']['customerGets']['value']['discountAmount']['appliesOnEachItem']);
        $shipping = $requests[2]['variables']['freeShippingCodeDiscount'];
        $this->assertSame(['all' => true], $shipping['destination']);
        $this->assertSame(['orderDiscounts' => false, 'productDiscounts' => false, 'shippingDiscounts' => false], $shipping['combinesWith']);
        Http::assertSentCount(3);
    }

    private function settings(array $replace = []): void
    {
        Settings::updateOrCreate(
            ['organization_id' => $this->organization->id, 'store_id' => $this->store->id],
            ['values' => $this->settingsInput($replace)],
        );
    }

    private function settingsInput(array $replace = []): array
    {
        return array_replace(config('deco_reviews.defaults'), [
            'rewards_enabled' => true, 'photo_reward_enabled' => true, 'video_reward_enabled' => true,
            'reward_discount_kind' => 'percentage', 'reward_value' => 10, 'reward_currency' => 'USD', 'reward_expiration_days' => 30,
        ], $replace);
    }

    private function review(array $replace = []): Review
    {
        return Review::create(array_replace([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'product_id' => $this->product->id, 'order_id' => $this->order->id, 'kind' => 'product',
            'author_name' => 'Verified Buyer', 'author_email' => 'buyer@example.test', 'email_hash' => app(ReviewService::class)->emailHash($this->store, 'buyer@example.test'),
            'rating' => 5, 'title' => 'Reward review', 'body' => 'Reward body '.Str::random(8), 'status' => 'published',
            'source' => 'email', 'verified_source' => 'order', 'published_at' => now(), 'reviewed_at' => now(),
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
        ], $replace));
    }

    private function media(Review $review, string $type): Media
    {
        return Media::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'review_id' => $review->id, 'path' => 'deco-reviews/test/'.Str::uuid(),
            'mime' => $type === 'video' ? 'video/mp4' : 'image/webp', 'size' => 100, 'type' => $type,
        ]);
    }

    private function enableWrites(): void
    {
        config([
            'deco_reviews.reward_writes_enabled' => true,
            'deco_reviews.automation_stores' => [$this->store->shopify_domain],
        ]);
    }
}
