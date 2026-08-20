<?php

namespace Tests\Feature;

use Laravel\Pulse\Recorders;
use Tests\TestCase;

class ObservabilityConfigurationTest extends TestCase
{
    public function test_horizon_uses_redis_with_the_expected_queue_priority(): void
    {
        $supervisor = config('horizon.defaults.supervisor-shopify');

        $this->assertSame('redis', $supervisor['connection']);
        $this->assertFalse($supervisor['balance']);
        $this->assertSame(3, $supervisor['processes']);
        $this->assertSame($supervisor['processes'], $supervisor['minProcesses']);
        $this->assertSame([
            'shopify-webhook',
            'shopify-sync',
            'shopify-analytics',
            'default',
            'notifications',
        ], $supervisor['queue']);
        $this->assertLessThan(
            config('queue.connections.redis.retry_after'),
            $supervisor['timeout'],
        );
    }

    public function test_pulse_has_the_required_local_observability_recorders(): void
    {
        $recorders = config('pulse.recorders');

        $this->assertArrayHasKey(Recorders\Exceptions::class, $recorders);
        $this->assertArrayHasKey(Recorders\Queues::class, $recorders);
        $this->assertArrayHasKey(Recorders\Servers::class, $recorders);
        $this->assertArrayHasKey(Recorders\SlowJobs::class, $recorders);
        $this->assertArrayHasKey(Recorders\SlowQueries::class, $recorders);
        $this->assertArrayHasKey(Recorders\SlowRequests::class, $recorders);
    }
}
