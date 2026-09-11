<?php

namespace Tests\Feature;

use App\Exceptions\AdvertisingApiException;
use App\Jobs\SyncAdvertisingChannelForStore;
use App\Models\Organization;
use App\Models\StoreAlert;
use App\Models\StoreBusinessCredential;
use App\Models\StoreSyncState;
use App\Models\SyncJob;
use App\Services\Advertising\AdvertisingChannelApiService;
use App\Services\Advertising\AdvertisingChannelSyncService;
use App\Services\Advertising\AdvertisingSyncFailurePolicy;
use App\Services\StoreAlertNotificationService;
use App\Services\StoreOperationalAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CriteoSyncResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-09T00:00:00Z'));
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function store(string $code = 'one')
    {
        $org = Organization::create(['name' => $code, 'code' => $code]);
        $store = $org->stores()->create(['name' => $code, 'shopify_domain' => $code.'.myshopify.com', 'status' => 'active']);
        foreach (['api_key', 'client_secret'] as $key) {
            StoreBusinessCredential::create(['organization_id' => $org->id, 'store_id' => $store->id,
                'provider' => 'criteo', 'credential_key' => $key, 'credential_value' => 'test-only']);
        }

        return $store;
    }

    public function test_backoff_alert_grace_deduplication_and_recovery(): void
    {
        $store = $this->store();
        $api = $this->mock(AdvertisingChannelApiService::class);
        $api->shouldReceive('syncPayload')->once()->andThrow(new AdvertisingApiException('sensitive response must not be stored', 429));
        $api->shouldReceive('syncPayload')->once()->andThrow(new AdvertisingApiException('another secret', 504));
        $api->shouldReceive('syncPayload')->once()->andReturn(['accounts' => [], 'daily_metrics' => []]);
        $sync = app(AdvertisingChannelSyncService::class);
        $version = $sync->credentialVersion($store, 'criteo');
        $worker = new SyncAdvertisingChannelForStore($store->organization_id, $store->id, 'criteo', 'incremental', $version);
        $worker->handle($sync);
        $job = SyncJob::sole();
        $this->assertSame('advertising_rate_limited', $job->error_code);
        $this->assertSame(1800, $sync->cooldownSeconds($store, 'criteo', $version));
        $this->assertStringNotContainsString('sensitive', json_encode($job->payload).$job->last_error);
        $worker->handle($sync); // Same task and API are not attempted during cooldown.
        $this->assertSame(1, $job->fresh()->attempts);
        $this->artisan('advertising-channels:sync', ['channel' => 'criteo'])->assertSuccessful();
        Queue::assertNothingPushed();
        $alerts = app(StoreOperationalAlertService::class);
        $this->assertSame(0, $alerts->scan($store)['created']);
        $this->travel(1)->hours();
        $worker->handle($sync);
        $this->assertSame(3600, $sync->cooldownSeconds($store, 'criteo', $version));
        $this->assertSame(1, $alerts->scan($store)['created']);
        $this->assertSame(0, $alerts->scan($store)['created']);
        $alert = StoreAlert::sole();
        $this->assertSame('Criteo 广告数据同步失败', $alert->title);
        $this->assertStringContainsString('HTTP 504', $alert->message);
        $this->assertStringNotContainsString('检查授权', $alert->message);
        $this->travel(1)->hours();
        $worker->handle($sync);
        $this->assertSame('completed', $job->fresh()->status);
        $this->assertSame(0, StoreSyncState::sole()->consecutive_failures);
        $this->assertSame('resolved', $alert->fresh()->status);
        app(StoreAlertNotificationService::class)->deliver($alert->fresh());
        $this->assertSame('skipped', $alert->fresh()->delivery_status);
        Http::assertNothingSent();
    }

    public function test_retry_after_cap_and_network_classification(): void
    {
        $this->assertSame(7200, AdvertisingSyncFailurePolicy::retryAfter('7200'));
        $this->assertSame(7200, AdvertisingSyncFailurePolicy::retryAfter('Wed, 09 Sep 2026 02:00:00 GMT'));
        $this->assertSame(0, AdvertisingSyncFailurePolicy::retryAfter('invalid'));
        $this->assertSame(86400, AdvertisingSyncFailurePolicy::delay(1, new AdvertisingApiException('safe', 429, 86400)));
        $this->assertSame(21600, AdvertisingSyncFailurePolicy::delay(20, new AdvertisingApiException('safe', 504)));
        $this->assertSame('advertising_network_error', AdvertisingSyncFailurePolicy::describe(new ConnectionException('private-url'))['code']);
    }

    public function test_authorization_errors_still_alert_immediately_and_are_not_swallowed(): void
    {
        $store = $this->store();
        $this->mock(AdvertisingChannelApiService::class)->shouldReceive('syncPayload')->once()->andThrow(new AdvertisingApiException('secret', 401));
        $sync = app(AdvertisingChannelSyncService::class);
        try {
            (new SyncAdvertisingChannelForStore($store->organization_id, $store->id, 'criteo'))->handle($sync);
            $this->fail('Authorization failure must not be silently consumed');
        } catch (AdvertisingApiException $e) {
            $this->assertSame(401, $e->httpStatus);
        }
        $this->assertSame(0, $sync->cooldownSeconds($store, 'criteo'));
        $this->assertSame(1, app(StoreOperationalAlertService::class)->scan($store)['created']);
        $this->assertStringContainsString('授权或权限', StoreAlert::sole()->message);
    }

    public function test_cooldown_is_store_scoped_and_changed_credentials_can_retry(): void
    {
        $store = $this->store();
        $other = $this->store('two');
        $this->mock(AdvertisingChannelApiService::class)->shouldReceive('syncPayload')->once()->andThrow(new AdvertisingApiException('safe', 429, 7200));
        $sync = app(AdvertisingChannelSyncService::class);
        $version = $sync->credentialVersion($store, 'criteo');
        (new SyncAdvertisingChannelForStore($store->organization_id, $store->id, 'criteo', 'priority', $version))->handle($sync);
        $this->assertSame(7200, $sync->cooldownSeconds($store, 'criteo', $version));
        $this->assertSame(0, $sync->cooldownSeconds($other, 'criteo', $sync->credentialVersion($other, 'criteo')));
        $this->assertSame(0, $sync->cooldownSeconds($store, 'criteo', 'new-version'));
        $this->assertSame('priority', $sync->resumeMode($store, 'criteo', 'incremental', $version));
        $this->travel(2)->hours();
        $this->artisan('advertising-channels:sync', ['channel' => 'criteo', '--store' => $store->id])->assertSuccessful();
        Queue::assertPushed(SyncAdvertisingChannelForStore::class, fn ($job) => $job->mode === 'priority' && $job->storeId === $store->id);
    }

    public function test_http_response_status_and_retry_after_are_retained_without_body(): void
    {
        $store = $this->store();
        Http::fake(['api.criteo.com/*' => Http::response(['private' => 'do-not-store'], 429, ['Retry-After' => '7200'])]);
        try {
            app(AdvertisingChannelApiService::class)->syncPayload($store, 'criteo', '2026-09-08', '2026-09-09');
            $this->fail('Should fail');
        } catch (AdvertisingApiException $e) {
            $this->assertSame(429, $e->httpStatus);
            $this->assertSame(7200, $e->retryAfterSeconds);
            $this->assertStringNotContainsString('do-not-store', $e->getMessage());
        }
    }
}
