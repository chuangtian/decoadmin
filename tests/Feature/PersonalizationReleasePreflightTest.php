<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalizationReleasePreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_check_requires_the_exact_test_contract_without_printing_secrets(): void
    {
        $this->validConfig();

        $this->artisan('personalization:release-check')
            ->expectsOutput('Personalization Test backend release check passed.')
            ->expectsOutput('Client ID: configured; Client Secret: configured; secrets were not displayed.')
            ->expectsOutput('Scopes: read_customer_events, read_discounts, write_app_proxy, write_discounts, write_pixels.')
            ->doesntExpectOutput('super-secret-value')
            ->assertSuccessful();
    }

    public function test_release_check_fails_closed_for_missing_secret_or_non_test_environment(): void
    {
        $this->validConfig();
        config([
            'personalization.environment' => 'disabled',
            'personalization.active.client_secret' => '',
        ]);

        $this->artisan('personalization:release-check')
            ->expectsOutputToContain('Personalization environment must be test.')
            ->expectsOutputToContain('Test Client Secret is not configured.')
            ->assertFailed();
    }

    private function validConfig(): void
    {
        config([
            'personalization.environment' => 'test',
            'personalization.active.client_id' => '1234567890abcdef1234567890abcdef',
            'personalization.active.client_secret' => 'super-secret-value',
            'personalization.active.name' => 'Deco 个性化推荐测试',
            'personalization.active.handle' => 'deco-personalization-test',
            'personalization.active.app_url' => 'https://testadmin.decomkt.com',
            'personalization.active_proxy_path' => '/apps/deco-personalization-test',
            'personalization.required_scopes' => ['write_app_proxy', 'write_pixels', 'read_customer_events', 'read_discounts', 'write_discounts'],
            'personalization.denied_shop_domains' => ['macfoxebike.myshopify.com'],
            'personalization.retention' => [
                'raw_event_days' => 90,
                'attribution_days' => 90,
                'aggregate_months' => 13,
                'audit_days' => 365,
                'uninstall_purge_hours' => 48,
                'backup_days' => 30,
            ],
        ]);
    }
}
