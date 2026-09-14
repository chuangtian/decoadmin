<?php

namespace Tests\DecoReviews;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use DecoReviews\Models\Invitation;
use DecoReviews\Models\Settings;
use DecoReviews\Services\InvitationDelivery;
use DecoReviews\Services\InvitationProcessor;
use DecoReviews\Services\ReviewService;
use DecoReviews\Services\ShopifyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class InvitationProcessorTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private Product $product;

    private Order $order;

    private Invitation $invite;

    protected function setUp(): void
    {
        parent::setUp();
        $config = require base_path('shopify-apps/deco-reviews/config/deco_reviews.php');
        config(['deco_reviews' => $config, 'cache.default' => 'array']);

        $organization = Organization::create([
            'name' => 'Processor test', 'code' => 'processor-'.Str::lower(Str::random(6)), 'status' => 'active',
        ]);
        $this->store = $organization->stores()->create([
            'name' => 'Safe unpublished test store',
            'shopify_domain' => 'deco-reviews-safety-test.myshopify.com',
            'status' => 'active',
            'country_code' => 'US',
        ]);
        $this->product = Product::create([
            'organization_id' => $organization->id, 'store_id' => $this->store->id,
            'shopify_product_id' => '7001', 'title' => 'Test Bike', 'handle' => 'test-bike',
            'status' => 'active', 'synced_at' => now(),
        ]);
        $this->order = Order::create([
            'organization_id' => $organization->id, 'store_id' => $this->store->id,
            'shopify_order_id' => '8001', 'order_number' => '#8001', 'email' => 'safe-buyer@example.test',
            'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled', 'currency' => 'USD',
            'total_price' => 100, 'subtotal_price' => 90, 'total_tax' => 10,
            'processed_at' => now()->subDays(30), 'created_at_shopify' => now()->subDays(30), 'synced_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $this->order->id, 'shopify_line_item_id' => '9001', 'product_id' => $this->product->id,
            'shopify_product_id' => $this->product->shopify_product_id, 'title' => $this->product->title,
            'quantity' => 1, 'current_quantity' => 1, 'price' => 90,
        ]);
        $this->invite = Invitation::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $organization->id, 'store_id' => $this->store->id,
            'order_id' => $this->order->id, 'product_id' => $this->product->id,
            'email' => $this->order->email, 'email_hash' => app(ReviewService::class)->emailHash($this->store, $this->order->email),
            'status' => 'verification_required', 'expires_at' => now()->addDays(90),
        ]);
        $this->settings(['invites_enabled' => true, 'marketing_only' => true, 'domestic_delay_days' => 14, 'international_delay_days' => 21]);
        config(['deco_reviews.automation_stores' => [$this->store->shopify_domain]]);
    }

    private function settings(array $replace): void
    {
        Settings::updateOrCreate(
            ['organization_id' => $this->store->organization_id, 'store_id' => $this->store->id],
            ['values' => array_replace(config('deco_reviews.defaults'), $replace)],
        );
    }

    private function snapshot(array $replace = [], array $fulfillments = []): array
    {
        $order = array_replace_recursive([
            'id' => 'gid://shopify/Order/8001', 'email' => 'safe-buyer@example.test',
            'cancelledAt' => null, 'displayFinancialStatus' => 'PAID',
            'shippingAddress' => ['countryCodeV2' => 'US'],
            'customer' => ['defaultEmailAddress' => ['emailAddress' => 'safe-buyer@example.test', 'marketingState' => 'SUBSCRIBED']],
            'lineItems' => ['nodes' => [['currentQuantity' => 1, 'product' => ['id' => 'gid://shopify/Product/7001']]], 'pageInfo' => ['hasNextPage' => false]],
            'fulfillments' => $fulfillments,
        ], $replace);

        return ['order' => $order, 'shop' => ['shopAddress' => ['countryCodeV2' => 'US']]];
    }

    private function fulfillment(string $createdAt, string $productId = '7001', bool $hasNextPage = false): array
    {
        return [
            'status' => 'SUCCESS', 'createdAt' => $createdAt,
            'fulfillmentLineItems' => [
                'pageInfo' => ['hasNextPage' => $hasNextPage],
                'nodes' => [['quantity' => 1, 'lineItem' => ['product' => ['id' => 'gid://shopify/Product/'.$productId]]]],
            ],
        ];
    }

    private function client(array $snapshot, int $times = 1): void
    {
        $mock = Mockery::mock(ShopifyClient::class);
        $mock->shouldReceive('order')->times($times)->with(
            Mockery::on(fn (Store $store) => $store->is($this->store)),
            (string) $this->order->shopify_order_id,
        )->andReturn($snapshot);
        app()->instance(ShopifyClient::class, $mock);
    }

    private function delivery(bool $allowed, ?bool $sent = null, int $allowedTimes = 1): void
    {
        $mock = Mockery::mock(InvitationDelivery::class);
        $mock->shouldReceive('allowed')->times($allowedTimes)->andReturn($allowed);
        if ($sent === null) {
            $mock->shouldNotReceive('send');
        } else {
            $mock->shouldReceive('send')->once()->andReturn($sent);
        }
        app()->instance(InvitationDelivery::class, $mock);
    }

    private function process(?int $organizationId = null, ?int $storeId = null): void
    {
        app(InvitationProcessor::class)->process(
            $organizationId ?? $this->store->organization_id,
            $storeId ?? $this->store->id,
            $this->invite->uuid,
        );
    }

    public function test_partial_product_fulfillment_waits_and_complete_split_delivery_uses_latest_date(): void
    {
        $old = now()->subDays(30);
        $recent = now()->subDays(2);
        $snapshot = $this->snapshot(fulfillments: [$this->fulfillment($old->toIso8601String())]);
        $snapshot['order']['lineItems']['nodes'][0]['currentQuantity'] = 2;
        $this->client($snapshot);
        $this->delivery(false, null, 0);
        $this->process();
        $this->assertSame('waiting_fulfillment', $this->invite->fresh()->status);
        $snapshot['order']['fulfillments'][] = $this->fulfillment($recent->toIso8601String());
        $this->client($snapshot);
        $this->process();
        $this->assertSame($recent->addDays(14)->toIso8601String(), $this->invite->fresh()->due_at->toIso8601String());
    }

    public function test_store_must_be_explicitly_allowlisted_and_invites_must_be_enabled(): void
    {
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldNotReceive('order');
        app()->instance(ShopifyClient::class, $client);
        $delivery = Mockery::mock(InvitationDelivery::class);
        $delivery->shouldNotReceive('allowed');
        $delivery->shouldNotReceive('send');
        app()->instance(InvitationDelivery::class, $delivery);

        config(['deco_reviews.automation_stores' => []]);
        $this->process();
        $this->assertSame('verification_required', $this->invite->fresh()->status);

        config(['deco_reviews.automation_stores' => [$this->store->shopify_domain]]);
        $this->settings(['invites_enabled' => false]);
        $this->process();
        $this->assertSame('verification_required', $this->invite->fresh()->status);
    }

    public function test_due_invitation_is_not_sent_when_delivery_is_not_explicitly_allowed(): void
    {
        $fulfilled = now()->subDays(30)->startOfSecond();
        $this->client($this->snapshot(fulfillments: [$this->fulfillment($fulfilled->toIso8601String())]));
        $this->delivery(false);
        $this->process();

        $invite = $this->invite->fresh();
        $this->assertSame('scheduled', $invite->status);
        $this->assertSame($fulfilled->addDays(14)->toIso8601String(), $invite->due_at->toIso8601String());
        $this->assertNull($invite->sent_at);
        $this->assertSame(0, $invite->attempts);
    }

    public function test_due_date_uses_exact_matching_product_fulfillment_and_domestic_or_international_delay(): void
    {
        $matching = now()->subDays(4)->startOfSecond();
        $wrongEarlier = now()->subDays(20)->startOfSecond();
        $this->client($this->snapshot(fulfillments: [
            $this->fulfillment($wrongEarlier->toIso8601String(), '9999'),
            $this->fulfillment($matching->toIso8601String()),
        ]));
        $this->delivery(false, null, 0);
        $this->process();
        $this->assertSame($matching->addDays(14)->toIso8601String(), $this->invite->fresh()->due_at->toIso8601String());

        $this->invite->refresh()->update(['status' => 'verification_required', 'due_at' => null]);
        $this->client($this->snapshot(['shippingAddress' => ['countryCodeV2' => 'CA']], [$this->fulfillment($matching->toIso8601String())]));
        $this->delivery(false, null, 0);
        $this->process();
        $this->assertSame($matching->addDays(21)->toIso8601String(), $this->invite->fresh()->due_at->toIso8601String());
    }

    public function test_wrong_product_waits_and_incomplete_fulfillment_page_fails_closed(): void
    {
        $time = now()->subDays(20)->toIso8601String();
        $this->client($this->snapshot(fulfillments: [$this->fulfillment($time, '9999')]));
        $this->process();
        $this->assertSame('waiting_fulfillment', $this->invite->fresh()->status);
        $this->assertNull($this->invite->fresh()->due_at);

        $this->invite->refresh()->update(['status' => 'verification_required', 'error_code' => null]);
        $this->client($this->snapshot(fulfillments: [$this->fulfillment($time, '7001', true)]));
        $this->process();
        $this->assertSame('FULFILLMENT_PAGE_INCOMPLETE', $this->invite->fresh()->error_code);
        $this->assertNull($this->invite->fresh()->sent_at);
    }

    public function test_marketing_consent_is_required_and_identity_must_match_scheduled_email(): void
    {
        $this->client($this->snapshot(['customer' => ['defaultEmailAddress' => ['marketingState' => 'NOT_SUBSCRIBED']]]));
        $this->process();
        $this->assertSame('CONSENT_REQUIRED', $this->invite->fresh()->error_code);
        $this->assertSame('verification_required', $this->invite->fresh()->status);

        $this->client($this->snapshot(['email' => 'changed@example.test', 'customer' => ['defaultEmailAddress' => ['emailAddress' => 'changed@example.test']]]));
        $this->process();
        $this->assertSame('ORDER_EMAIL_CHANGED', $this->invite->fresh()->error_code);
        $this->assertNull($this->invite->fresh()->sent_at);
    }

    public function test_cancelled_shopify_order_is_rejected(): void
    {
        $this->client($this->snapshot(['cancelledAt' => now()->toIso8601String()]));
        $this->process();
        $this->assertSame('cancelled', $this->invite->fresh()->status);
        $this->assertSame('ORDER_NOT_ELIGIBLE', $this->invite->fresh()->error_code);

    }

    public function test_refunded_shopify_order_is_rejected(): void
    {
        $this->client($this->snapshot(['displayFinancialStatus' => 'REFUNDED']));
        $this->process();
        $this->assertSame('cancelled', $this->invite->fresh()->status);
        $this->assertSame('ORDER_NOT_ELIGIBLE', $this->invite->fresh()->error_code);
        $this->assertNull($this->invite->fresh()->sent_at);
    }

    public function test_uncertain_delivery_result_is_held_and_never_retried(): void
    {
        $fulfilled = now()->subDays(30)->toIso8601String();
        $this->client($this->snapshot(fulfillments: [$this->fulfillment($fulfilled)]));
        $this->delivery(true, false);
        $this->process();
        $invite = $this->invite->fresh();
        $this->assertSame('held', $invite->status);
        $this->assertSame(1, $invite->attempts);
        $this->assertSame('DELIVERY_RESULT_REQUIRES_REVIEW', $invite->error_code);
        $this->assertNull($invite->sent_at);

        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldNotReceive('order');
        app()->instance(ShopifyClient::class, $client);
        $delivery = Mockery::mock(InvitationDelivery::class);
        $delivery->shouldNotReceive('allowed');
        $delivery->shouldNotReceive('send');
        app()->instance(InvitationDelivery::class, $delivery);
        $this->process();
        $this->assertSame(1, $this->invite->fresh()->attempts);
    }

    public function test_processor_requires_exact_organization_store_and_invitation_scope(): void
    {
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldNotReceive('order');
        app()->instance(ShopifyClient::class, $client);
        $delivery = Mockery::mock(InvitationDelivery::class);
        $delivery->shouldNotReceive('allowed');
        $delivery->shouldNotReceive('send');
        app()->instance(InvitationDelivery::class, $delivery);

        $this->process($this->store->organization_id + 999, $this->store->id);
        $this->process($this->store->organization_id, $this->store->id + 999);
        $this->assertSame('verification_required', $this->invite->fresh()->status);
        $this->assertNull($this->invite->fresh()->sent_at);
    }
}
