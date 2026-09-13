<?php

namespace Tests\DecoReviews;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use DecoReviews\Models\Invitation;
use DecoReviews\Services\InvitationDelivery;
use DecoReviews\Services\InvitationProcessor;
use DecoReviews\Services\InvitationService;
use DecoReviews\Services\ReviewService;
use DecoReviews\Services\ShopifyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class InvitationAutomationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    private Store $store;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $config = require base_path('shopify-apps/deco-reviews/config/deco_reviews.php');
        config(['deco_reviews' => $config, 'cache.default' => 'array']);
        Cache::flush();
        $this->user = User::factory()->create(['email_verified_at' => now(), 'metadata' => ['is_super_admin' => true]]);
        $this->organization = Organization::create(['name' => 'Automation Test', 'code' => 'automation-'.Str::lower(Str::random(6)), 'status' => 'active']);
        $this->organization->users()->attach($this->user, ['status' => 'active', 'joined_at' => now()]);
        $this->store = $this->organization->stores()->create(['name' => 'Safe Test Store', 'shopify_domain' => 'deco-reviews-automation-test.myshopify.com', 'status' => 'active']);
        $this->store->members()->attach($this->user, ['status' => 'active', 'joined_at' => now()]);
        $this->product = Product::create(['organization_id' => $this->organization->id, 'store_id' => $this->store->id,
            'shopify_product_id' => '501', 'title' => 'Safe Test Bike', 'handle' => 'safe-test-bike', 'status' => 'active', 'synced_at' => now()]);
    }

    private function settingsInput(array $replace = []): array
    {
        return array_replace(config('deco_reviews.defaults'), $replace);
    }

    private function saveSettings(array $replace = []): array
    {
        app(ReviewService::class)->saveSettings($this->store, $this->user, $this->settingsInput($replace));

        return app(ReviewService::class)->settings($this->store);
    }

    private function order(Store $store, Product $product, int $externalId, array $replace = []): Order
    {
        $order = Order::create(array_replace(['organization_id' => $store->organization_id, 'store_id' => $store->id,
            'shopify_order_id' => (string) $externalId, 'order_number' => '#'.$externalId, 'email' => 'buyer'.$externalId.'@example.test',
            'financial_status' => 'paid', 'fulfillment_status' => 'fulfilled', 'currency' => 'USD', 'total_price' => 100,
            'subtotal_price' => 90, 'total_tax' => 10, 'processed_at' => now(), 'created_at_shopify' => now(), 'synced_at' => now()], $replace));
        OrderItem::create(['order_id' => $order->id, 'shopify_line_item_id' => (string) ($externalId + 10000), 'product_id' => $product->id,
            'shopify_product_id' => $product->shopify_product_id, 'title' => $product->title, 'quantity' => 1, 'current_quantity' => 1, 'price' => 90]);

        return $order;
    }

    private function invitation(Order $order, string $status = 'sent', array $replace = []): Invitation
    {
        return Invitation::create(array_replace(['uuid' => (string) Str::uuid(), 'organization_id' => $this->organization->id,
            'store_id' => $this->store->id, 'order_id' => $order->id, 'product_id' => $this->product->id,
            'email' => $order->email, 'email_hash' => app(ReviewService::class)->emailHash($this->store, $order->email),
            'status' => $status, 'sent_at' => $status === 'sent' ? now()->subDays(8) : null, 'expires_at' => now()->addDays(60)], $replace));
    }

    private function snapshot(Order $order): array
    {
        return ['order' => ['email' => $order->email, 'cancelledAt' => null, 'displayFinancialStatus' => 'PAID',
            'shippingAddress' => ['countryCodeV2' => 'US'],
            'customer' => ['defaultEmailAddress' => ['emailAddress' => $order->email, 'marketingState' => 'SUBSCRIBED']],
            'lineItems' => ['nodes' => [['currentQuantity' => 1, 'product' => ['id' => 'gid://shopify/Product/'.$this->product->shopify_product_id]]], 'pageInfo' => ['hasNextPage' => false]],
            'fulfillments' => [['status' => 'SUCCESS', 'createdAt' => now()->subDays(30)->toIso8601String(),
                'fulfillmentLineItems' => ['pageInfo' => ['hasNextPage' => false], 'nodes' => [['quantity' => 1,
                    'lineItem' => ['product' => ['id' => 'gid://shopify/Product/'.$this->product->shopify_product_id]]]]]]]],
            'shop' => ['shopAddress' => ['countryCodeV2' => 'US']]];
    }

    public function test_auto_invite_activation_timestamp_is_server_owned_and_stable_until_disabled(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        $enabled = $this->saveSettings(['invites_enabled' => true, 'auto_invites_enabled' => true,
            'auto_invites_since' => '2000-01-01T00:00:00Z']);
        $this->assertSame(now()->toIso8601String(), $enabled['auto_invites_since']);

        $this->travel(2)->hours();
        $stillEnabled = $this->saveSettings(['invites_enabled' => true, 'auto_invites_enabled' => true,
            'auto_invites_since' => '2099-01-01T00:00:00Z']);
        $this->assertSame($enabled['auto_invites_since'], $stillEnabled['auto_invites_since']);

        $disabled = $this->saveSettings(['auto_invites_enabled' => false, 'auto_invites_since' => '2099-01-01T00:00:00Z']);
        $this->assertNull($disabled['auto_invites_since']);
    }

    public function test_discovery_is_allowlisted_enabled_scoped_to_new_paid_orders_and_idempotent(): void
    {
        $this->travelTo('2026-09-13 10:00:00');
        $settings = $this->saveSettings(['invites_enabled' => true, 'auto_invites_enabled' => true]);
        $eligible = $this->order($this->store, $this->product, 1001, ['created_at_shopify' => now()->addMinute()]);
        $this->order($this->store, $this->product, 1002, ['created_at_shopify' => now()->subMinute()]);
        $this->order($this->store, $this->product, 1003, ['created_at_shopify' => now()->addMinute(), 'financial_status' => 'pending']);
        $this->order($this->store, $this->product, 1004, ['created_at_shopify' => now()->addMinute(), 'cancelled_at' => now()]);
        [, $foreignOrganization, $foreignStore, $foreignProduct] = $this->foreignContext();
        $this->order($foreignStore, $foreignProduct, 2001, ['created_at_shopify' => now()->addMinute()]);

        config(['deco_reviews.automation_stores' => []]);
        $this->assertSame(0, app(InvitationService::class)->discover($this->store));
        config(['deco_reviews.automation_stores' => [$this->store->shopify_domain]]);
        $this->assertSame(1, app(InvitationService::class)->discover($this->store));
        $this->assertSame(0, app(InvitationService::class)->discover($this->store));
        $invite = Invitation::sole();
        $this->assertSame($eligible->id, $invite->order_id);
        $this->assertSame($this->store->id, $invite->store_id);
        $this->assertSame($this->organization->id, $invite->organization_id);
        $this->assertDatabaseMissing('deco_review_invitations', ['organization_id' => $foreignOrganization->id]);
        $this->assertSame(now()->toIso8601String(), $settings['auto_invites_since']);
    }

    private function foreignContext(): array
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'metadata' => ['is_super_admin' => true]]);
        $organization = Organization::create(['name' => 'Foreign', 'code' => 'foreign-'.Str::lower(Str::random(5)), 'status' => 'active']);
        $store = $organization->stores()->create(['name' => 'Foreign', 'shopify_domain' => 'foreign-'.Str::lower(Str::random(5)).'.myshopify.com', 'status' => 'active']);
        $product = Product::create(['organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_product_id' => '9901',
            'title' => 'Foreign product', 'handle' => 'foreign-product', 'status' => 'active', 'synced_at' => now()]);

        return [$user, $organization, $store, $product];
    }

    public function test_invitation_sender_is_store_branded_without_changing_shared_mail_settings(): void
    {
        $this->saveSettings(['invites_enabled' => true, 'reminders_enabled' => true]);
        config(['mail.from.address' => 'sender@example.test', 'mail.from.name' => 'Student Discount']);
        $order = $this->order($this->store, $this->product, 2999);
        $invite = $this->invitation($order, 'sending');
        $delivery = Mockery::mock(InvitationDelivery::class)->makePartial();
        $delivery->shouldReceive('allowed')->twice()->andReturnTrue();
        Mail::shouldReceive('send')->twice()->andReturnUsing(function ($view, $content, $callback) use ($invite) {
            $this->assertSame(['html' => 'deco-reviews::emails.invitation', 'text' => 'deco-reviews::emails.invitation-text'], $view);
            $email = new \Symfony\Component\Mime\Email;
            $callback(new \Illuminate\Mail\Message($email));
            $this->assertSame('sender@example.test', $email->getFrom()[0]->getAddress());
            $this->assertSame('Safe Test Store Reviews', $email->getFrom()[0]->getName());
            $this->assertSame($invite->email, $email->getTo()[0]->getAddress());
            $this->assertSame('Student Discount', config('mail.from.name'));

            return new \stdClass;
        });
        $this->assertTrue($delivery->send($this->store, $invite));
        $invite->update(['status' => 'sending_reminder']);
        $this->assertTrue($delivery->send($this->store, $invite));
    }

    public function test_reminder_sends_once_and_completed_or_unsubscribed_invitations_never_send(): void
    {
        $this->saveSettings(['invites_enabled' => true, 'reminders_enabled' => true, 'reminder_days' => 7]);
        config(['deco_reviews.automation_stores' => [$this->store->shopify_domain]]);
        $order = $this->order($this->store, $this->product, 3001);
        $invite = $this->invitation($order);
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldReceive('order')->once()->andReturn($this->snapshot($order));
        app()->instance(ShopifyClient::class, $client);
        $delivery = Mockery::mock(InvitationDelivery::class);
        $delivery->shouldReceive('allowed')->once()->andReturnTrue();
        $delivery->shouldReceive('send')->once()->andReturnTrue();
        app()->instance(InvitationDelivery::class, $delivery);
        app(InvitationProcessor::class)->process($this->organization->id, $this->store->id, $invite->uuid);
        $this->assertSame('sent', $invite->fresh()->status);
        $this->assertNotNull($invite->fresh()->reminder_sent_at);

        $noClient = Mockery::mock(ShopifyClient::class);
        $noClient->shouldNotReceive('order');
        app()->instance(ShopifyClient::class, $noClient);
        $noDelivery = Mockery::mock(InvitationDelivery::class);
        $noDelivery->shouldNotReceive('allowed');
        $noDelivery->shouldNotReceive('send');
        app()->instance(InvitationDelivery::class, $noDelivery);
        app(InvitationProcessor::class)->process($this->organization->id, $this->store->id, $invite->uuid);
        foreach (['completed', 'unsubscribed'] as $index => $status) {
            $otherOrder = $this->order($this->store, $this->product, 3010 + $index);
            $other = $this->invitation($otherOrder, $status, ['sent_at' => now()->subDays(8)]);
            app(InvitationProcessor::class)->process($this->organization->id, $this->store->id, $other->uuid);
            $this->assertSame($status, $other->fresh()->status);
            $this->assertNull($other->fresh()->reminder_sent_at);
        }
    }

    public function test_uncertain_reminder_result_is_held_and_never_retried(): void
    {
        $this->saveSettings(['invites_enabled' => true, 'reminders_enabled' => true, 'reminder_days' => 7]);
        config(['deco_reviews.automation_stores' => [$this->store->shopify_domain]]);
        $order = $this->order($this->store, $this->product, 4001);
        $invite = $this->invitation($order);
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldReceive('order')->once()->andReturn($this->snapshot($order));
        app()->instance(ShopifyClient::class, $client);
        $delivery = Mockery::mock(InvitationDelivery::class);
        $delivery->shouldReceive('allowed')->once()->andReturnTrue();
        $delivery->shouldReceive('send')->once()->andReturnFalse();
        app()->instance(InvitationDelivery::class, $delivery);
        app(InvitationProcessor::class)->process($this->organization->id, $this->store->id, $invite->uuid);
        $this->assertSame('reminder_held', $invite->fresh()->status);
        $this->assertNull($invite->fresh()->reminder_sent_at);
        $attempts = $invite->fresh()->attempts;

        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldNotReceive('order');
        app()->instance(ShopifyClient::class, $client);
        $delivery = Mockery::mock(InvitationDelivery::class);
        $delivery->shouldNotReceive('allowed');
        $delivery->shouldNotReceive('send');
        app()->instance(InvitationDelivery::class, $delivery);
        app(InvitationProcessor::class)->process($this->organization->id, $this->store->id, $invite->uuid);
        $this->assertSame($attempts, $invite->fresh()->attempts);
    }

    public function test_initial_and_reminder_previews_are_escaped_authorized_and_never_send(): void
    {
        Mail::fake();
        $this->saveSettings(['subject' => '<script>Initial</script> {store}', 'email_body' => '<b>Initial body</b>',
            'reminder_subject' => '<script>Reminder</script> {store}', 'reminder_body' => '<img src=x onerror=alert(1)>',
            'email_button_label' => '<Click>', 'email_accent' => '#123456']);
        $base = "/organizations/{$this->organization->id}/stores/{$this->store->id}/deco-reviews/email-preview";

        $this->get($base.'?kind=initial')->assertRedirect('/login');
        $unauthorized = User::factory()->create(['email_verified_at' => now()]);
        $this->organization->users()->attach($unauthorized, ['status' => 'active', 'joined_at' => now()]);
        $this->store->members()->attach($unauthorized, ['status' => 'active', 'joined_at' => now()]);
        $this->actingAs($unauthorized)->get($base.'?kind=initial')->assertForbidden();
        foreach (['initial' => 'Initial', 'reminder' => 'Reminder'] as $kind => $expected) {
            $response = $this->actingAs($this->user)->get($base.'?kind='.$kind)->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeader('Content-Security-Policy');
            $html = $response->getContent();
            $this->assertStringContainsString('&lt;script&gt;'.$expected.'&lt;/script&gt;', $html);
            $this->assertStringNotContainsString('<script>'.$expected.'</script>', $html);
            $this->assertStringContainsString('&lt;Click&gt;', $html);
            $this->assertStringContainsString('DEMO PREVIEW', $html);
            if ($kind === 'reminder') {
                $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
                $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
            }
        }
        Mail::assertNothingSent();
        $this->assertDatabaseCount('deco_review_invitations', 0);
    }
}
