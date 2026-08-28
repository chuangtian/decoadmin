<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

class ShopifyCliProjectTest extends TestCase
{
    public function test_shopify_cli_configs_match_the_hosted_laravel_entrypoints(): void
    {
        $project = base_path('shopify-apps/decoAfterShip');
        $scopes = 'read_products,read_inventory,read_orders,read_customers,read_locations,read_reports,read_discounts,write_discounts';

        $this->assertDirectoryExists($project);
        $this->assertFileExists("{$project}/package.json");
        $this->assertFileExists("{$project}/shopify.app.toml");
        $this->assertFileExists("{$project}/shopify.app.test.toml");
        $this->assertFileExists("{$project}/shopify.app.production.toml");
        $this->assertFileDoesNotExist("{$project}/shopify.app.local.toml");

        foreach ([
            'shopify.app.toml' => ['https://testadmin.decomkt.com', '/shopify/aftership'],
            'shopify.app.test.toml' => ['https://testadmin.decomkt.com', '/shopify/aftership'],
            'shopify.app.production.toml' => ['https://admin.decomkt.com', '/shopify'],
        ] as $file => [$origin, $path]) {
            $contents = file_get_contents("{$project}/{$file}");

            $this->assertIsString($contents);
            $this->assertStringContainsString("application_url = \"{$origin}{$path}/launch\"", $contents);
            $this->assertStringContainsString("\"{$origin}{$path}/oauth/callback\"", $contents);
            $this->assertStringContainsString('embedded = false', $contents);
            $this->assertStringContainsString("scopes = \"{$scopes}\"", $contents);
            $this->assertStringContainsString('use_legacy_install_flow = true', $contents);
            $this->assertStringContainsString('automatically_update_urls_on_dev = false', $contents);
            $this->assertDoesNotMatchRegularExpression('/trycloudflare\.com|localhost|127\.0\.0\.1/i', $contents);
            $this->assertDoesNotMatchRegularExpression('/client_secret|access_token/i', $contents);
        }

        $this->assertSame('shopify/launch', Route::getRoutes()->getByName('shopify.app.launch')?->uri());
        $this->assertSame('shopify/oauth/callback', Route::getRoutes()->getByName('shopify.oauth.callback')?->uri());
        $this->assertSame('shopify/aftership/launch', Route::getRoutes()->getByName('aftership.shopify.app.launch')?->uri());
        $this->assertSame('shopify/aftership/oauth/callback', Route::getRoutes()->getByName('aftership.shopify.oauth.callback')?->uri());
    }

    public function test_shopify_cli_project_does_not_duplicate_laravel_or_other_apps(): void
    {
        $project = base_path('shopify-apps/decoAfterShip');

        $this->assertFileDoesNotExist("{$project}/artisan");
        $this->assertFileDoesNotExist("{$project}/composer.json");
        $this->assertDirectoryDoesNotExist("{$project}/app");

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($project, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relativePath = strtolower(str_replace('\\', '/', substr($file->getPathname(), strlen($project) + 1)));

            $this->assertStringNotContainsString('student-discount', $relativePath);
            $this->assertStringNotContainsString('student_discount', $relativePath);
        }
    }
}
