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
            if (! $file->isFile() || in_array($file->getFilename(), ['AGENTS.md', 'README.md', 'package-lock.json', 'validate-project.mjs'], true)) {
                continue;
            }

            $relative = str_replace($root.'/', '', $file->getPathname());
            $contents = (string) file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('macfox-test-app', $contents, $relative);
            $this->assertStringNotContainsString('macfoxebike', $contents, $relative);
            $this->assertStringNotContainsString('trycloudflare.com', $contents, $relative);
            $this->assertDoesNotMatchRegularExpression('/read_discounts|write_discounts|student_discount|instagram_feed/i', $contents, $relative);
        }
    }
}
