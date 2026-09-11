<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookEventJob;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\ShopifyConnection;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AffiliateWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_receiver_checks_hmac_deduplicates_and_keeps_payload_encrypted(): void
    {
        [$store,$app] = $this->context();
        Queue::fake();
        $raw = '{"id":7085121995000}';
        $uuid = (string) Str::uuid();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $raw, 'referral-test-secret', true)),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $uuid, 'HTTP_X_SHOPIFY_TOPIC' => 'orders/paid', 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $store->shopify_domain];
        $this->call('POST', '/api/shopify-app/referral/webhooks', [], [], [], $headers, $raw)->assertOk()->assertJson(['duplicate' => false]);
        $this->call('POST', '/api/shopify-app/referral/webhooks', [], [], [], $headers, $raw)->assertOk()->assertJson(['duplicate' => true]);
        Queue::assertPushed(ProcessWebhookEventJob::class, 1);
        $event = WebhookEvent::query()->sole();
        $this->assertSame($raw, $event->rawPayload());
        $this->assertNotSame($raw, $event->getRawOriginal('payload_encrypted'));
        $headers['HTTP_X_SHOPIFY_HMAC_SHA256'] = 'bad';
        $this->call('POST', '/api/shopify-app/referral/webhooks', [], [], [], $headers, $raw)->assertUnauthorized();
        $this->assertDatabaseCount('webhook_events', 1);
    }

    public function test_uninstall_revokes_only_the_referral_installation_immediately(): void
    {
        [$store,$app] = $this->context();
        Queue::fake();
        $install = AppInstallation::query()->create(['shopify_connection_id' => $store->shopifyConnection->id, 'granted_scopes' => ['read_orders'], 'store_id' => $store->id, 'app_id' => $app->id, 'status' => 'active', 'access_token_encrypted' => 'owned-token']);
        $other = App::query()->create(['name' => 'Other', 'handle' => 'other-test', 'status' => 'active']);
        $otherInstall = AppInstallation::query()->create(['shopify_connection_id' => $store->shopifyConnection->id, 'granted_scopes' => ['read_orders'], 'store_id' => $store->id, 'app_id' => $other->id, 'status' => 'active', 'access_token_encrypted' => 'other-token']);
        $raw = '{"myshopify_domain":"macfox-test-app.myshopify.com"}';
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $raw, 'referral-test-secret', true)),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => (string) Str::uuid(), 'HTTP_X_SHOPIFY_TOPIC' => 'app/uninstalled', 'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $store->shopify_domain];
        $this->call('POST', '/api/shopify-app/referral/webhooks', [], [], [], $headers, $raw)->assertOk();
        $this->assertNull($install->fresh()->access_token_encrypted);
        $this->assertSame('uninstalled', $install->fresh()->status);
        $this->assertSame('other-token', $otherInstall->fresh()->access_token_encrypted);
    }

    private function context(): array
    {
        config(['referral.active.client_secret' => 'referral-test-secret', 'referral.active.client_id' => 'referral-client', 'referral.active.handle' => 'deco-referral-test', 'referral.environment' => 'test']);
        $org = Organization::query()->create(['name' => 'Webhook test', 'code' => 'webhook-test']);
        $store = $org->stores()->create(['name' => 'Test', 'shopify_domain' => 'macfox-test-app.myshopify.com', 'currency' => 'USD', 'status' => 'active']);
        ShopifyConnection::query()->create(['store_id' => $store->id, 'shop_domain' => $store->shopify_domain, 'status' => 'connected', 'access_token_encrypted' => 'commerce', 'scopes' => ['read_orders'], 'api_version' => '2026-07']);
        $app = App::query()->create(['name' => 'Referral', 'handle' => 'deco-referral-test', 'client_id' => 'referral-client', 'status' => 'active', 'settings' => ['environment' => 'test']]);

        return [$store, $app];
    }
}
