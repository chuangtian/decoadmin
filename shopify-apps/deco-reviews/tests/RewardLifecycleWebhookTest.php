<?php

namespace Tests\DecoReviews;

use App\Models\Organization;
use App\Models\Store;
use DecoReviews\Models\Review;
use DecoReviews\Models\Reward;
use DecoReviews\Models\RewardDelivery;
use DecoReviews\Services\RewardEmailDelivery;
use DecoReviews\Services\RewardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RewardLifecycleWebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'deco-reviews-webhook-test-secret';

    private Organization $organization;

    private Store $store;

    private Reward $reward;

    protected function setUp(): void
    {
        parent::setUp();
        config(['deco_reviews' => require base_path('shopify-apps/deco-reviews/config/deco_reviews.php')]);
        config(['deco_reviews.active.client_secret' => $this->secret]);
        $this->organization = Organization::create([
            'name' => 'Reward Lifecycle', 'code' => 'reward-lifecycle-'.Str::lower(Str::random(8)), 'status' => 'active',
        ]);
        $this->store = $this->organization->stores()->create([
            'name' => 'Lifecycle Store', 'shopify_domain' => 'lifecycle-'.Str::lower(Str::random(8)).'.myshopify.com', 'status' => 'active',
        ]);
        $review = Review::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'kind' => 'store', 'author_name' => 'Lifecycle Buyer', 'rating' => 5, 'body' => 'Lifecycle reward review',
            'status' => 'published', 'source' => 'email', 'verified_source' => 'none', 'reviewed_at' => now(),
            'fingerprint' => hash('sha256', Str::uuid()->toString()),
        ]);
        $this->reward = Reward::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'review_id' => $review->id, 'media_kind' => 'photo', 'discount_kind' => 'percentage', 'value' => 10,
            'expiration_days' => 30, 'code' => 'DECO-TRACK-TEST', 'code_hash' => hash('sha256', 'DECO-TRACK-TEST'),
            'shopify_discount_id' => 'gid://shopify/DiscountCodeNode/123', 'status' => 'issued', 'issued_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function test_paid_refund_and_cancel_track_one_reward_idempotently(): void
    {
        $reminder = RewardDelivery::create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'reward_id' => $this->reward->id, 'type' => 'reward_reminder', 'recipient' => 'buyer@example.test',
            'recipient_hash' => hash('sha256', 'buyer@example.test'), 'dedupe_key' => hash('sha256', Str::uuid()->toString()),
            'status' => 'scheduled', 'due_at' => now()->addDays(5),
        ]);
        $paid = ['id' => 9001, 'processed_at' => '2026-09-14T09:00:00Z', 'discount_codes' => [
            ['code' => 'unrelated'], ['code' => 'deco-track-test'],
        ]];
        $this->webhook('orders/paid', $paid, 'webhook-paid-1')->assertOk();
        $this->webhook('orders/paid', $paid, 'webhook-paid-1')->assertOk();
        $reward = $this->reward->fresh();
        $this->assertSame('redeemed', $reward->status);
        $this->assertSame('9001', $reward->redeemed_order_id);
        $this->assertSame('2026-09-14T09:00:00+00:00', $reward->redeemed_at->toIso8601String());
        $this->assertDatabaseCount('deco_review_webhook_receipts', 1);
        $history = app(RewardService::class)->history($this->store)[0];
        $this->assertSame('redeemed', $history['status']);
        $this->assertArrayNotHasKey('redeemed_order_id', $history);
        $this->assertStringNotContainsString('DECO-TRACK-TEST', json_encode($history));
        $this->assertSame('REWARD_ALREADY_REDEEMED', app(RewardEmailDelivery::class)->cancellationCode($this->store, $reminder->load('reward')));

        $this->webhook('refunds/create', ['order_id' => 9001, 'processed_at' => '2026-09-15T10:00:00Z'], 'webhook-refund-1')->assertOk();
        $this->webhook('orders/cancelled', ['id' => 9001, 'cancelled_at' => '2026-09-16T11:00:00Z'], 'webhook-cancel-1')->assertOk();
        $reward = $this->reward->fresh();
        $this->assertSame('redeemed', $reward->status);
        $this->assertSame('2026-09-15T10:00:00+00:00', $reward->refunded_at->toIso8601String());
        $this->assertSame('2026-09-16T11:00:00+00:00', $reward->cancelled_order_at->toIso8601String());
        $this->assertDatabaseCount('deco_review_rewards', 1);
        $this->assertDatabaseCount('deco_review_webhook_receipts', 3);
    }

    public function test_tracking_is_store_scoped_and_receipts_exclude_payload_and_code(): void
    {
        $foreignOrganization = Organization::create(['name' => 'Foreign', 'code' => 'foreign-'.Str::lower(Str::random(8)), 'status' => 'active']);
        $foreignStore = $foreignOrganization->stores()->create([
            'name' => 'Foreign', 'shopify_domain' => 'foreign-'.Str::lower(Str::random(8)).'.myshopify.com', 'status' => 'active',
        ]);
        $payload = ['id' => 9002, 'discount_codes' => [['code' => 'DECO-TRACK-TEST']]];
        $this->webhook('orders/paid', $payload, null, $foreignStore)->assertOk();
        $this->webhook('orders/paid', $payload, null, $foreignStore)->assertOk();
        $this->assertSame('issued', $this->reward->fresh()->status);
        $receipt = (array) DB::table('deco_review_webhook_receipts')->sole();
        $serialized = json_encode($receipt);
        $this->assertSame($foreignStore->id, $receipt['store_id']);
        $this->assertStringNotContainsString('DECO-TRACK-TEST', $serialized);
        $this->assertStringNotContainsString('discount_codes', $serialized);
        $this->assertDatabaseCount('deco_review_webhook_receipts', 1);
    }

    public function test_invalid_signature_and_unknown_topic_do_not_change_reward(): void
    {
        $payload = ['id' => 9003, 'discount_codes' => [['code' => 'DECO-TRACK-TEST']]];
        $this->webhook('orders/paid', $payload, 'bad-signature', null, false)->assertUnauthorized();
        $this->webhook('orders/updated', $payload, 'unknown-topic')->assertOk();
        $this->assertSame('issued', $this->reward->fresh()->status);
        $this->assertDatabaseCount('deco_review_webhook_receipts', 0);
    }

    public function test_receipt_pruning_is_bounded_and_keeps_recent_records(): void
    {
        $base = ['organization_id' => $this->organization->id, 'store_id' => $this->store->id, 'topic' => 'orders/paid',
            'payload_hash' => hash('sha256', 'payload')];
        DB::table('deco_review_webhook_receipts')->insert($base + [
            'webhook_id' => 'old-receipt', 'processed_at' => now()->subDays(91),
        ]);
        DB::table('deco_review_webhook_receipts')->insert($base + [
            'webhook_id' => 'recent-receipt', 'processed_at' => now()->subDays(89),
        ]);

        $this->artisan('deco-reviews:prune-webhook-receipts')->assertSuccessful();

        $this->assertDatabaseMissing('deco_review_webhook_receipts', ['webhook_id' => 'old-receipt']);
        $this->assertDatabaseHas('deco_review_webhook_receipts', ['webhook_id' => 'recent-receipt']);
    }

    private function webhook(string $topic, array $payload, ?string $webhookId, ?Store $store = null, bool $valid = true): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $hmac = base64_encode(hash_hmac('sha256', $body, $valid ? $this->secret : 'wrong-secret', true));
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => ($store ?? $this->store)->shopify_domain,
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
        ];
        if ($webhookId !== null) {
            $server['HTTP_X_SHOPIFY_WEBHOOK_ID'] = $webhookId;
        }

        return $this->call('POST', '/api/shopify-app/deco-reviews/webhooks', [], [], [], $server, $body);
    }
}
