<?php

namespace Tests\Feature;

use Tests\TestCase;

class ShopifyPersonalizationProjectTest extends TestCase
{
    public function test_personalization_project_is_test_first_and_has_no_runnable_local_or_production_config(): void
    {
        $root = base_path('shopify-apps/deco-personalization');
        $required = [
            'AGENTS.md',
            'README.md',
            'package.json',
            'shopify.app.toml',
            'shopify.app.local.toml',
            'shopify.app.test.toml',
            'shopify.app.production.toml',
            'scripts/validate-project.mjs',
            'extensions/app-home/shopify.extension.toml',
            'extensions/app-home/src/AppHome.jsx',
            'extensions/app-home/src/runtime.mjs',
            'extensions/recommendations/shopify.extension.toml',
            'extensions/recommendations/blocks/recommendations.liquid',
            'extensions/recommendations/blocks/smart_cart.liquid',
            'extensions/recommendations/assets/recommendations.js',
            'extensions/recommendations/assets/recommendations.css',
            'extensions/recommendations/assets/smart-cart.js',
            'extensions/recommendations/assets/smart-cart.css',
        ];

        foreach ($required as $file) {
            $this->assertFileExists("{$root}/{$file}");
        }

        $package = json_decode((string) file_get_contents("{$root}/package.json"), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('deco-personalization', $package['name']);
        $this->assertTrue($package['private']);
        $this->assertArrayNotHasKey('dev', $package['scripts']);
        $this->assertArrayNotHasKey('deploy:local', $package['scripts']);
        $this->assertArrayNotHasKey('deploy:production', $package['scripts']);

        foreach (['shopify.app.local.toml', 'shopify.app.production.toml'] as $file) {
            $contents = (string) file_get_contents("{$root}/{$file}");
            $this->assertDoesNotMatchRegularExpression(
                '/^\s*(client_id|application_url|name|handle|scopes|redirect_urls|url|uri)\s*=/m',
                $contents,
            );
        }
    }

    public function test_personalization_runtime_files_do_not_hardcode_a_shop_or_another_app_domain(): void
    {
        $root = base_path('shopify-apps/deco-personalization');
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            $relative = str_replace($root.'/', '', $file->getPathname());
            if (! $file->isFile()
                || str_starts_with($relative, 'node_modules/')
                || str_starts_with($relative, '.shopify/')
                || in_array($file->getFilename(), ['AGENTS.md', 'README.md', 'package-lock.json', 'validate-project.mjs'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('macfox-test-app', $contents, $relative);
            if ($relative === 'extensions/app-home/src/runtime.mjs') {
                $this->assertStringContainsString('macfoxebike.myshopify.com', $contents, $relative);
            } elseif ($relative !== 'extensions/app-home/src/runtime.test.mjs') {
                $this->assertStringNotContainsString('macfoxebike', $contents, $relative);
            }
            $this->assertStringNotContainsString('trycloudflare.com', $contents, $relative);
            $this->assertDoesNotMatchRegularExpression('/read_discounts|write_discounts|student_discount|instagram_feed/i', $contents, $relative);
        }
    }
}
