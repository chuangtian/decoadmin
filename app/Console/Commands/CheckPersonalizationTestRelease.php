<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class CheckPersonalizationTestRelease extends Command
{
    protected $signature = 'personalization:release-check';

    protected $description = 'Verify the active DecoAdmin backend before publishing the matching Personalization App';

    public function handle(): int
    {
        $failures = [];
        $check = function (bool $condition, string $message) use (&$failures): void {
            if (! $condition) {
                $failures[] = $message;
            }
        };

        $environment = (string) config('personalization.environment');
        $contracts = [
            'test' => [
                'label' => 'Test',
                'name' => 'Deco 个性化推荐测试',
                'handle' => 'deco-personalization-test',
                'origin' => 'https://testadmin.decomkt.com',
                'proxy_path' => '/apps/deco-personalization-test',
            ],
            'production' => [
                'label' => 'Production',
                'name' => 'Deco 个性化推荐',
                'handle' => 'deco-personalization',
                'origin' => 'https://admin.decomkt.com',
                'proxy_path' => '/apps/deco-personalization',
            ],
        ];
        $contract = $contracts[$environment] ?? null;
        $label = (string) ($contract['label'] ?? 'Active');
        $clientId = trim((string) config('personalization.active.client_id'));
        $secretConfigured = (string) config('personalization.active.client_secret') !== '';
        $scopes = array_values((array) config('personalization.required_scopes', []));
        sort($scopes);
        $expectedScopes = ['read_customer_events', 'read_discounts', 'write_app_proxy', 'write_discounts', 'write_pixels'];
        $retention = (array) config('personalization.retention', []);

        $check($contract !== null, 'Personalization environment must be test or production.');
        $check(preg_match('/^[a-f0-9]{32}$/i', $clientId) === 1, "{$label} Client ID is missing or invalid.");
        $check($secretConfigured, "{$label} Client Secret is not configured.");
        if ($contract !== null) {
            $check((string) config('personalization.active.name') === $contract['name'], "Unexpected {$label} app display name.");
            $check((string) config('personalization.active.handle') === $contract['handle'], "Unexpected {$label} app handle.");
            $check((string) config('personalization.active.app_url') === $contract['origin'], "Unexpected {$label} backend origin.");
            $check((string) config('personalization.active_proxy_path') === $contract['proxy_path'], "Unexpected {$label} App Proxy path.");
        }
        $check($scopes === $expectedScopes, 'Personalization scopes exceed or omit the approved P0 minimum.');
        $check(in_array('macfoxebike.myshopify.com', (array) config('personalization.denied_shop_domains', []), true), 'Permanent denied shop guard is missing.');

        foreach ([
            'personalization_recommendation_strategies',
            'personalization_recommendation_components',
            'personalization_smart_cart_settings',
            'personalization_event_sources',
            'personalization_events',
            'personalization_attributions',
            'personalization_daily_metrics',
        ] as $table) {
            $check(Schema::hasTable($table), "Missing database table: {$table}.");
        }
        foreach ([
            'personalization.shopify-app.management',
            'personalization.shopify-app.connection',
            'personalization.shopify-app.bootstrap',
            'personalization.shopify-app.webhooks',
            'personalization.events.receive',
            'personalization.public.recommendations',
            'personalization.public.smart-cart',
        ] as $route) {
            $check(Route::has($route), "Missing backend route: {$route}.");
        }
        $check((int) ($retention['raw_event_days'] ?? 0) === 90, 'Raw event retention must be 90 days.');
        $check((int) ($retention['attribution_days'] ?? 0) === 90, 'Attribution detail retention must be 90 days.');
        $check((int) ($retention['aggregate_months'] ?? 0) === 13, 'Aggregate retention must be 13 months.');
        $check((int) ($retention['audit_days'] ?? 0) === 365, 'Audit retention must be 365 days.');
        $check((int) ($retention['uninstall_purge_hours'] ?? 0) <= 72, 'Online uninstall purge must complete within 72 hours.');
        $check((int) ($retention['backup_days'] ?? 0) <= 30, 'Backup retention must not exceed 30 days.');

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error($failure);
            }

            return self::FAILURE;
        }

        $this->info("Personalization {$label} backend release check passed.");
        $this->line('Client ID: configured; Client Secret: configured; secrets were not displayed.');
        $this->line('Scopes: '.implode(', ', $scopes).'.');

        return self::SUCCESS;
    }
}
