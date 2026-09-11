<?php

namespace Tests\Feature;

use Tests\TestCase;

class AffiliateWorkerConfigurationTest extends TestCase
{
    public function test_referral_queues_survive_standard_staging_startup_without_enabling_production(): void
    {
        $config = require base_path('config/horizon.php');
        $worker = $config['environments']['staging']['supervisor-referral'];
        $this->assertSame(['affiliate', 'affiliate-notifications'], $worker['queue']);
        $this->assertSame(100, $worker['timeout']);
        $this->assertArrayNotHasKey('supervisor-referral', $config['environments']['production']);
        $this->assertArrayNotHasKey('supervisor-referral', $config['defaults']);
    }
}
