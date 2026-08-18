<?php

namespace Tests\Feature;

use App\Contracts\Shopify\WebhookHandlerInterface;
use App\Jobs\ProcessWebhookEventJob;
use App\Models\App;
use App\Models\Organization;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\WebhookEvent;
use App\Services\Shopify\Webhooks\WebhookEventProcessor;
use App\Services\Shopify\Webhooks\WebhookEventStateService;
use App\Services\Shopify\Webhooks\WebhookHandlerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ShopifyWebhookProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_processor_dispatches_event_to_matching_handler(): void
    {
        $handler = new class implements WebhookHandlerInterface
        {
            public bool $handled = false;

            public function topic(): string
            {
                return 'orders/create';
            }

            public function handle(WebhookEvent $event): void
            {
                $this->handled = $event->topic === $this->topic();
            }
        };
        $processor = new WebhookEventProcessor(new WebhookHandlerRegistry([$handler]));
        $result = $processor->process(new WebhookEvent(['topic' => 'orders/create']));

        $this->assertTrue($handler->handled);
        $this->assertSame('handled', $result->result);
        $this->assertSame($handler::class, $result->handler);
        $this->assertNull($result->reason);
    }

    public function test_unsupported_topic_is_recorded_without_failing_job(): void
    {
        [$event] = $this->eventContext('customers/redact', 'queued');
        $processor = new WebhookEventProcessor(new WebhookHandlerRegistry([]));

        (new ProcessWebhookEventJob($event->id))->handle(
            $processor,
            app(WebhookEventStateService::class),
        );

        $event->refresh();
        $this->assertSame('processed', $event->status);
        $this->assertSame('unsupported', $event->processing_result);
        $this->assertNull($event->handler);
        $this->assertStringContainsString('customers/redact', $event->unsupported_reason);
        $this->assertNull($event->last_error);
    }

    public function test_handler_exception_marks_event_failed_and_redacts_secrets(): void
    {
        [$event] = $this->eventContext('orders/create', 'queued');
        $handler = new class implements WebhookHandlerInterface
        {
            public function topic(): string
            {
                return 'orders/create';
            }

            public function handle(WebhookEvent $event): void
            {
                throw new RuntimeException('Failure includes shpat_processing_token and processing-secret');
            }
        };
        $processor = new WebhookEventProcessor(new WebhookHandlerRegistry([$handler]));

        try {
            (new ProcessWebhookEventJob($event->id))->handle(
                $processor,
                app(WebhookEventStateService::class),
            );
            $this->fail('Expected handler exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Failure includes', $exception->getMessage());
        }

        $event->refresh();
        $this->assertSame('failed', $event->status);
        $this->assertSame('failed', $event->processing_result);
        $this->assertSame(1, $event->attempts);
        $this->assertNotNull($event->processing_started_at);
        $this->assertNotNull($event->processing_duration_ms);
        $this->assertNotNull($event->next_retry_at);
        $this->assertStringContainsString('[redacted]', $event->last_error);
        $this->assertStringNotContainsString('shpat_processing_token', $event->last_error);
        $this->assertStringNotContainsString('processing-secret', $event->last_error);
    }

    public function test_state_service_moves_received_event_to_queued_before_processing(): void
    {
        [$event] = $this->eventContext('products/update', 'received');
        $states = app(WebhookEventStateService::class);

        $states->markQueued($event);
        $this->assertSame('queued', $event->fresh()->status);

        (new ProcessWebhookEventJob($event->id))->handle(
            app(WebhookEventProcessor::class),
            $states,
        );

        $event->refresh();
        $this->assertSame('processed', $event->status);
        $this->assertSame('handled', $event->processing_result);
        $this->assertStringEndsWith('ProductsUpdatedHandler', $event->handler);
        $this->assertNotNull($event->processed_at);
    }

    /** @return array{0: WebhookEvent, 1: Organization, 2: Store} */
    private function eventContext(string $topic, string $status): array
    {
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox']);
        $store = $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => 'macfox-us.myshopify.com',
            'status' => 'active',
        ]);
        $app = App::query()->create([
            'name' => 'Processing App',
            'handle' => 'processing-app',
            'client_secret_encrypted' => 'processing-secret',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'shpat_processing_token',
            'token_type' => 'offline',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        $rawPayload = json_encode($topic === 'products/update' ? [
            'id' => 123,
            'title' => 'Webhook Product',
            'handle' => 'webhook-product',
            'status' => 'active',
            'vendor' => 'DecoAdmin',
            'product_type' => 'Test',
            'body_html' => '<p>Updated by webhook.</p>',
            'variants' => [[
                'id' => 456,
                'title' => 'Default Title',
                'sku' => 'WEBHOOK-123',
                'price' => '19.99',
                'inventory_item_id' => 789,
            ]],
        ] : ['id' => 123], JSON_THROW_ON_ERROR);
        $event = WebhookEvent::query()->create([
            'webhook_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'app_id' => $app->id,
            'topic' => $topic,
            'api_version' => '2026-07',
            'headers' => ['topic' => $topic, 'shop_domain' => $store->shopify_domain],
            'payload' => ['storage' => 'encrypted'],
            'payload_encrypted' => $rawPayload,
            'payload_sha256' => hash('sha256', $rawPayload),
            'status' => $status,
            'attempts' => 0,
            'received_at' => now(),
        ]);

        return [$event, $organization, $store];
    }
}
