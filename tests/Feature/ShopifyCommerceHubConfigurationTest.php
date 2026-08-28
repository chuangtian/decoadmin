<?php

namespace Tests\Feature;

use Tests\TestCase;

class ShopifyCommerceHubConfigurationTest extends TestCase
{
    public function test_backend_and_shopify_app_configs_keep_the_same_safe_scope_contract(): void
    {
        $expectedScopes = [
            'read_products',
            'read_inventory',
            'read_orders',
            'read_customers',
            'read_locations',
            'read_reports',
        ];
        $expectedBackendScopes = implode(',', $expectedScopes);
        $expectedSortedScopes = $expectedScopes;
        sort($expectedSortedScopes);

        $envExample = file_get_contents(base_path('.env.example'));
        $this->assertIsString($envExample);
        $this->assertMatchesRegularExpression(
            '/^SHOPIFY_REQUESTED_SCOPES='.preg_quote($expectedBackendScopes, '/').'$/m',
            $envExample,
        );

        $backendConfig = file_get_contents(config_path('shopify.php'));
        $this->assertIsString($backendConfig);
        $this->assertStringContainsString(
            "env('SHOPIFY_REQUESTED_SCOPES', '{$expectedBackendScopes}')",
            $backendConfig,
        );

        $configurations = [
            'shopify.app.toml' => ['8060ab64cae19ff20a05814f1276d716', 'https://consistent-menu-herself-telephony.trycloudflare.com'],
            'shopify.app.local.toml' => ['8060ab64cae19ff20a05814f1276d716', 'https://consistent-menu-herself-telephony.trycloudflare.com'],
            'shopify.app.test.toml' => ['c6921ca2233c5069033577d3cd1759ea', 'https://testadmin.decomkt.com'],
            'shopify.app.production.toml' => ['52f415f03423fe1f1838dcfa7a0fca9a', 'https://admin.decomkt.com'],
        ];

        foreach ($configurations as $fileName => [$clientId, $origin]) {
            $contents = file_get_contents(base_path("shopify-apps/commerce-hub/{$fileName}"));
            $this->assertIsString($contents);
            $this->assertMatchesRegularExpression('/^client_id\s*=\s*"'.preg_quote($clientId, '/').'"$/m', $contents);
            $this->assertMatchesRegularExpression('/^application_url\s*=\s*"'.preg_quote($origin, '/').'"$/m', $contents);
            $this->assertStringContainsString('use_legacy_install_flow = true', $contents);
            $this->assertStringContainsString(
                'redirect_urls = ["'.$origin.'/shopify/oauth/callback"]',
                $contents,
            );
            $this->assertStringNotContainsString('read_discounts', $contents);
            $this->assertStringNotContainsString('write_discounts', $contents);

            preg_match('/^scopes\s*=\s*"([^"]+)"$/m', $contents, $matches);
            $this->assertArrayHasKey(1, $matches);
            $actualScopes = array_map('trim', explode(',', $matches[1]));
            sort($actualScopes);
            $this->assertSame($expectedSortedScopes, $actualScopes, "{$fileName} scopes drifted from the backend contract");
        }

        $this->assertCount(3, array_unique(array_column($configurations, 0)));
    }
}
