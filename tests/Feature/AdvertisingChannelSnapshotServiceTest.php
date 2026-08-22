<?php

namespace Tests\Feature;

use App\Jobs\RefreshAdvertisingChannelSnapshot;
use App\Models\AnalyticsSnapshot;
use App\Models\Organization;
use App\Services\Advertising\AdvertisingChannelApiService;
use App\Services\Advertising\AdvertisingChannelSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AdvertisingChannelSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_snapshot_queues_each_platform_and_later_reads_only_the_completed_mysql_snapshot(): void
    {
        Queue::fake();
        $organization = Organization::query()->create(['name' => 'Ads Snapshot Org', 'code' => 'ads-snapshot-org']);
        $store = $organization->stores()->create([
            'name' => 'Ads Snapshot Store',
            'shopify_domain' => 'ads-snapshot.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'UTC',
        ]);
        $keys = ['facebook', 'google', 'tiktok', 'bing', 'criteo'];
        $values = [
            'facebook' => [450.0, 370.0],
            'google' => [220.0, 240.0],
            'tiktok' => [90.0, 110.0],
            'bing' => [80.0, 110.0],
            'criteo' => [160.0, 170.0],
        ];
        $api = Mockery::mock(AdvertisingChannelApiService::class);
        $api->shouldReceive('channelKeys')->andReturn($keys);
        foreach ($keys as $key) {
            $api->shouldReceive('fetchChannel')->once()->withArgs(
                fn ($reportedStore, string $reportedKey, string $from, string $to): bool => $reportedStore->is($store)
                    && $reportedKey === $key
                    && $from === '2026-02-10'
                    && $to === '2026-02-15',
            )
                ->andReturn([
                    'key' => $key,
                    'name' => ucfirst($key),
                    'configured' => true,
                    'available' => true,
                    'ad_spend' => $values[$key][0],
                    'attributed_sales' => $values[$key][1],
                    'message' => null,
                ]);
        }
        $service = new AdvertisingChannelSnapshotService($api);

        $pending = $service->report($store, '2026-02-10', '2026-02-15');

        $this->assertFalse($pending['available']);
        $this->assertTrue($pending['pending']);
        $this->assertTrue($pending['storage']['persisted']);
        Queue::assertPushed(RefreshAdvertisingChannelSnapshot::class, 5);

        $snapshot = AnalyticsSnapshot::query()->sole();
        $generation = (string) data_get($snapshot->payload, 'refresh_generation');
        foreach ($keys as $key) {
            $service->refreshChannel($snapshot->id, $key, $generation);
        }

        $stored = $service->report($store, '2026-02-10', '2026-02-15');
        $this->assertTrue($stored['available']);
        $this->assertTrue($stored['complete']);
        $this->assertFalse($stored['pending']);
        $this->assertFalse($stored['storage']['stale']);
        $this->assertSame('advertising_apis', $stored['source']);
        $this->assertCount(5, $stored['channels']);
        $this->assertEquals(450.0, $stored['channels'][0]['ad_spend']);
        $this->assertEquals(370.0, $stored['channels'][0]['attributed_sales']);
        Queue::assertPushed(RefreshAdvertisingChannelSnapshot::class, 5);
    }
}
