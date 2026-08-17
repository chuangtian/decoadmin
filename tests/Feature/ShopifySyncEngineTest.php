<?php

namespace Tests\Feature;

use App\Contracts\Shopify\SyncHandlerInterface;
use App\Exceptions\ShopifyApiException;
use App\Jobs\ProcessSyncJob;
use App\Models\Organization;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\SyncJob;
use App\Services\Shopify\ShopifyGraphQLClient;
use App\Services\Shopify\Sync\Handlers\CustomerSyncHandler;
use App\Services\Shopify\Sync\Handlers\InventorySyncHandler;
use App\Services\Shopify\Sync\Handlers\OrderSyncHandler;
use App\Services\Shopify\Sync\Handlers\ProductSyncHandler;
use App\Services\Shopify\Sync\SyncHandlerRegistry;
use App\Services\Shopify\Sync\SyncProcessor;
use App\Services\Shopify\Sync\SyncResult;
use App\Services\Sync\SyncJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ShopifySyncEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_registry_resolves_every_supported_handler(): void
    {
        $registry = app(SyncHandlerRegistry::class);

        $this->assertInstanceOf(ProductSyncHandler::class, $registry->forType('products'));
        $this->assertInstanceOf(OrderSyncHandler::class, $registry->forType('orders'));
        $this->assertInstanceOf(CustomerSyncHandler::class, $registry->forType('customers'));
        $this->assertInstanceOf(InventorySyncHandler::class, $registry->forType('inventory'));
        $this->assertSame(['products', 'orders', 'customers', 'inventory'], $registry->types());
    }

    public function test_unknown_sync_type_returns_unsupported_and_marks_job_failed(): void
    {
        $syncJob = $this->syncJob('unknown-resource');

        $result = app(SyncProcessor::class)->process($syncJob->id);
        $syncJob->refresh();

        $this->assertFalse($result->success);
        $this->assertSame('unsupported', $result->status);
        $this->assertSame('unsupported_sync_type', $result->errors[0]['code']);
        $this->assertSame('failed', $syncJob->status);
        $this->assertSame('unsupported', $syncJob->result['status']);
    }

    public function test_processor_executes_handler_and_persists_standard_result(): void
    {
        $syncJob = $this->syncJob('test-resource');
        $handler = new class implements SyncHandlerInterface
        {
            public function type(): string
            {
                return 'test-resource';
            }

            public function handle(SyncJob $syncJob): SyncResult
            {
                return SyncResult::successful('Test completed.', metadata: [
                    'handler' => self::class,
                    'framework_only' => true,
                ]);
            }
        };
        $processor = new SyncProcessor(
            new SyncHandlerRegistry([$handler]),
            app(SyncJobService::class),
        );

        $result = $processor->process($syncJob->id);
        $syncJob->refresh();

        $this->assertTrue($result->success);
        $this->assertSame('success', $result->status);
        $this->assertSame(0, $result->recordsCount);
        $this->assertSame('completed', $syncJob->status);
        $this->assertSame([
            'success', 'status', 'message', 'records_count', 'errors', 'metadata',
        ], array_keys($syncJob->result));
        $this->assertSame($handler::class, $syncJob->result['metadata']['handler']);
        $this->assertTrue($syncJob->result['metadata']['framework_only']);
    }

    public function test_queue_job_delegates_to_processor_on_shopify_sync_queue(): void
    {
        $syncJob = $this->syncJob('inventory');
        $processor = Mockery::mock(SyncProcessor::class);
        $processor->shouldReceive('process')
            ->once()
            ->with($syncJob->id)
            ->andReturn(SyncResult::successful('ok'));

        $job = new ProcessSyncJob($syncJob->id);
        $job->handle($processor);

        $this->assertSame('shopify-sync', $job->queue);
        $this->assertSame(3, $job->tries);
    }

    public function test_failed_handler_can_be_retried_by_the_queue_processor(): void
    {
        $syncJob = $this->syncJob('products');
        $handler = new class implements SyncHandlerInterface
        {
            public int $calls = 0;

            public function type(): string
            {
                return 'products';
            }

            public function handle(SyncJob $syncJob): SyncResult
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new RuntimeException('Temporary Shopify API failure.');
                }

                return SyncResult::successful('Retry completed.');
            }
        };
        $processor = new SyncProcessor(
            new SyncHandlerRegistry([$handler]),
            app(SyncJobService::class),
        );

        try {
            $processor->process($syncJob->id);
            $this->fail('Expected first attempt to fail.');
        } catch (RuntimeException) {
            // Horizon receives the exception and retries the same queue job.
        }

        $this->assertSame('failed', $syncJob->fresh()->status);
        $result = $processor->process($syncJob->id);
        $syncJob->refresh();

        $this->assertTrue($result->success);
        $this->assertSame('completed', $syncJob->status);
        $this->assertSame(2, $syncJob->attempts);
        $this->assertNull($syncJob->last_error);
    }

    public function test_graphql_sync_query_returns_standard_payload_and_throttle_status(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => ['shop' => ['id' => 'gid://shopify/Shop/1']],
                'extensions' => [
                    'cost' => [
                        'throttleStatus' => ['currentlyAvailable' => 900],
                    ],
                ],
            ]),
        ]);

        $result = app(ShopifyGraphQLClient::class)->executeSyncQuery(
            $this->connection(),
            'query SyncTest { shop { id } }',
        );

        $this->assertSame('gid://shopify/Shop/1', $result['data']['shop']['id']);
        $this->assertSame(900, $result['throttle_status']['currentlyAvailable']);
    }

    public function test_graphql_rate_limit_is_classified_as_retryable(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '2'])]);

        try {
            app(ShopifyGraphQLClient::class)->executeSyncQuery(
                $this->connection(),
                'query SyncTest { shop { id } }',
            );
            $this->fail('Expected Shopify API exception.');
        } catch (ShopifyApiException $exception) {
            $this->assertSame('rate_limit', $exception->context['error_type']);
            $this->assertTrue($exception->context['retryable']);
            $this->assertSame('2', $exception->context['retry_after']);
            $this->assertArrayNotHasKey('access_token', $exception->context);
        }
    }

    public function test_graphql_timeout_is_classified_without_exposing_token(): void
    {
        Http::fake(['*' => Http::failedConnection('Connection timed out')]);

        try {
            app(ShopifyGraphQLClient::class)->executeSyncQuery(
                $this->connection(),
                'query SyncTest { shop { id } }',
            );
            $this->fail('Expected Shopify API exception.');
        } catch (ShopifyApiException $exception) {
            $this->assertSame('timeout', $exception->context['error_type']);
            $this->assertTrue($exception->context['retryable']);
            $this->assertArrayNotHasKey('access_token', $exception->context);
        }
    }

    public function test_graphql_server_error_is_classified_as_retryable_api_error(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        try {
            app(ShopifyGraphQLClient::class)->executeSyncQuery(
                $this->connection(),
                'query SyncTest { shop { id } }',
            );
            $this->fail('Expected Shopify API exception.');
        } catch (ShopifyApiException $exception) {
            $this->assertSame('api_error', $exception->context['error_type']);
            $this->assertTrue($exception->context['retryable']);
            $this->assertSame(500, $exception->context['status']);
        }
    }

    private function syncJob(string $type): SyncJob
    {
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => Str::random(12)]);
        $store = $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => Str::random(12).'.myshopify.com',
            'status' => 'active',
        ]);

        return SyncJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'type' => $type,
            'direction' => 'pull',
            'status' => 'queued',
            'logs' => [],
        ]);
    }

    private function connection(): ShopifyConnection
    {
        $organization = Organization::query()->create(['name' => 'Deco', 'code' => Str::random(12)]);
        $store = Store::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Deco Test',
            'shopify_domain' => Str::random(12).'.myshopify.com',
            'status' => 'active',
        ]);

        return ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'test-token',
            'token_type' => 'offline',
            'scopes' => [],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
    }
}
