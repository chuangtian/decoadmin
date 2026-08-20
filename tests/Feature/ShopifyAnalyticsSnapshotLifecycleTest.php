<?php

namespace Tests\Feature;

use App\Jobs\RefreshShopifyAnalyticsSnapshot;
use App\Models\AnalyticsSnapshot;
use App\Models\Organization;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Services\Shopify\Analytics\ShopifyAnalyticsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShopifyAnalyticsSnapshotLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_request_does_not_call_shopify_when_an_identical_refresh_lock_is_held(): void
    {
        [$organization, $store] = $this->context();
        config()->set('shopify.analytics_snapshot_refresh_wait_seconds', 1);
        Http::fake();

        $from = '2026-08-01';
        $to = '2026-08-20';
        $reportKey = 'catalog:acquisition-by-source';
        $lockKey = implode(':', [
            'shopify-analytics-refresh',
            $organization->id,
            $store->id,
            sha1($reportKey),
            $from,
            $to,
            'v2',
        ]);
        $lock = Cache::lock($lockKey, 90);
        $this->assertTrue($lock->get());

        try {
            $result = app(ShopifyAnalyticsReportService::class)
                ->report($store, 'acquisition-by-source', $from, $to);
        } finally {
            $lock->release();
        }

        $this->assertFalse($result['available']);
        $this->assertSame('Shopify 报表正在刷新，请稍后重试。', $result['error']);
        Http::assertNothingSent();
    }

    public function test_stale_snapshot_is_returned_and_refreshed_by_the_unique_background_job(): void
    {
        [$organization, $store] = $this->context();
        Queue::fake();
        Http::preventStrayRequests();

        $snapshot = $this->snapshot($organization, $store, [
            'scope_granted' => true,
            'available' => true,
            'source' => 'shopifyql',
            'rows' => [['referrer_source' => 'old', 'sessions' => '1']],
            'error' => null,
        ], now()->subMinute());

        $result = app(ShopifyAnalyticsReportService::class)
            ->report($store, 'acquisition-by-source', '2026-08-01', '2026-08-20');

        $this->assertTrue($result['available']);
        $this->assertTrue($result['storage']['stale']);
        $this->assertSame('old', $result['rows'][0]['referrer_source']);
        Http::assertNothingSent();
        Queue::assertPushed(RefreshShopifyAnalyticsSnapshot::class, function ($job) use ($snapshot): bool {
            return $job->snapshotId === $snapshot->id
                && $job->uniqueId() === (string) $snapshot->id
                && $job->queue === 'shopify-analytics';
        });

        Http::fake(fn (Request $request) => Http::response(['data' => ['shopifyqlQuery' => [
            'tableData' => ['columns' => [], 'rows' => [[
                'referrer_source' => 'new',
                'referrer_name' => 'Search',
                'sessions' => '20',
            ]]],
            'parseErrors' => [],
        ]]]));

        (new RefreshShopifyAnalyticsSnapshot($snapshot->id))
            ->handle(app(ShopifyAnalyticsReportService::class));

        Http::assertSentCount(1);
        $snapshot->refresh();
        $this->assertSame('new', $snapshot->payload['rows'][0]['referrer_source']);
        $this->assertTrue($snapshot->expires_at->isFuture());
    }

    public function test_prune_command_deletes_only_snapshots_older_than_retention_period(): void
    {
        [$organization, $store] = $this->context();
        config()->set('shopify.analytics_snapshot_retention_days', 30);
        config()->set('shopify.analytics_snapshot_prune_batch_size', 100);

        $old = $this->snapshot($organization, $store, ['available' => true], now()->subDays(31));
        $recent = $this->snapshot(
            $organization,
            $store,
            ['available' => true],
            now()->subDays(29),
            'analytics-overview',
        );
        $fresh = $this->snapshot(
            $organization,
            $store,
            ['available' => true],
            now()->addMinutes(15),
            'business-overview',
        );

        $this->artisan('shopify:prune-analytics-snapshots')
            ->expectsOutput('Deleted 1 analytics snapshots older than 30 days.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('analytics_snapshots', ['id' => $old->id]);
        $this->assertDatabaseHas('analytics_snapshots', ['id' => $recent->id]);
        $this->assertDatabaseHas('analytics_snapshots', ['id' => $fresh->id]);
    }

    public function test_snapshot_cleanup_is_registered_with_the_scheduler(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('shopify:prune-analytics-snapshots')
            ->assertSuccessful();
    }

    /** @return array{Organization, Store} */
    private function context(): array
    {
        $organization = Organization::query()->create([
            'name' => 'Snapshot Organization',
            'code' => 'snapshot-organization',
        ]);
        $store = $organization->stores()->create([
            'name' => 'Snapshot Store',
            'shopify_domain' => 'snapshot-store.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
            'currency' => 'USD',
        ]);
        ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'token',
            'token_type' => 'offline',
            'scopes' => ['read_reports'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);

        return [$organization, $store->load('shopifyConnection')];
    }

    /** @param array<string, mixed> $payload */
    private function snapshot(
        Organization $organization,
        Store $store,
        array $payload,
        mixed $expiresAt,
        string $reportKey = 'catalog:acquisition-by-source',
    ): AnalyticsSnapshot {
        return AnalyticsSnapshot::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'report_key' => $reportKey,
            'period_from' => '2026-08-01',
            'period_to' => '2026-08-20',
            'timezone' => 'UTC',
            'source' => 'shopifyql',
            'schema_version' => 2,
            'payload' => $payload,
            'fetched_at' => now()->subHour(),
            'expires_at' => $expiresAt,
        ]);
    }
}
