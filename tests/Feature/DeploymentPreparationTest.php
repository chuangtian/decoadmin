<?php

namespace Tests\Feature;

use App\Logging\SensitiveDataProcessor;
use App\Services\DeploymentHealthService;
use DateTimeImmutable;
use Mockery;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class DeploymentPreparationTest extends TestCase
{
    public function test_shared_app_image_is_built_once_per_compose_environment(): void
    {
        $productionCompose = file_get_contents(base_path('compose.production.yaml'));
        $localCompose = file_get_contents(base_path('compose.yaml'));

        $this->assertMatchesRegularExpression('/x-app: &app\n  image:/', $productionCompose);
        $this->assertMatchesRegularExpression('/  app:\n    <<: \*app\n    build:\n      context: \.\n      target: app-production/', $productionCompose);
        $this->assertMatchesRegularExpression('/x-app: &app\n  image:/', $localCompose);
        $this->assertMatchesRegularExpression('/  app:\n    <<: \*app\n    build:\n      context: \.\n      target: app/', $localCompose);
    }

    public function test_production_storage_link_is_not_recreated_during_setup(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $entrypoint = file_get_contents(base_path('docker/php/entrypoint.sh'));

        $this->assertStringContainsString('ln -s ../storage/app/public public/storage', $dockerfile);
        $this->assertStringContainsString('if [ ! -e public/storage ] && [ ! -L public/storage ]; then', $entrypoint);
        $this->assertStringContainsString('php artisan storage:link --no-interaction', $entrypoint);
        $this->assertStringNotContainsString('storage:link --force', $entrypoint);
    }

    public function test_setup_refreshes_package_discovery_before_using_persistent_bootstrap_cache(): void
    {
        $entrypoint = file_get_contents(base_path('docker/php/entrypoint.sh'));

        $configRemoval = strpos($entrypoint, 'rm -f bootstrap/cache/config.php');
        $configClear = strpos($entrypoint, 'php artisan config:clear --no-interaction');
        $packageDiscovery = strpos($entrypoint, 'php artisan package:discover --ansi --no-interaction');
        $optimization = strpos($entrypoint, 'php artisan optimize --no-interaction');

        $this->assertNotFalse($configRemoval);
        $this->assertNotFalse($configClear);
        $this->assertNotFalse($packageDiscovery);
        $this->assertNotFalse($optimization);
        $this->assertLessThan($configClear, $configRemoval);
        $this->assertLessThan($packageDiscovery, $configClear);
        $this->assertLessThan($optimization, $packageDiscovery);
    }

    public function test_nginx_compresses_responses_and_caches_versioned_build_assets(): void
    {
        $config = file_get_contents(base_path('docker/nginx/default.conf'));

        $this->assertIsString($config);
        $this->assertStringContainsString('gzip on;', $config);
        $this->assertStringContainsString('gzip_vary on;', $config);
        $this->assertStringContainsString('location ^~ /build/assets/', $config);
        $this->assertStringContainsString('Cache-Control "public, max-age=31536000, immutable"', $config);
        $this->assertStringContainsString('try_files $uri =404;', $config);
    }

    public function test_health_endpoint_checks_application_database_and_redis(): void
    {
        $this->getJson(route('health'))
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.application.status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.redis.status', 'ok')
            ->assertJsonStructure(['timestamp']);
    }

    public function test_health_endpoint_returns_service_unavailable_without_internal_error_details(): void
    {
        $health = Mockery::mock(DeploymentHealthService::class);
        $health->shouldReceive('check')->once()->andReturn([
            'status' => 'degraded',
            'checks' => [
                'application' => ['status' => 'ok'],
                'database' => ['status' => 'error'],
                'redis' => ['status' => 'ok'],
            ],
        ]);
        $this->app->instance(DeploymentHealthService::class, $health);

        $response = $this->getJson(route('health'))
            ->assertServiceUnavailable()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonMissing(['exception' => true]);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    public function test_sensitive_log_processor_redacts_secrets_headers_and_payloads(): void
    {
        $record = new LogRecord(
            new DateTimeImmutable,
            'deployment-test',
            Level::Error,
            'Request failed access_token=top-secret Bearer abc123',
            [
                'access_token' => 'top-secret',
                'headers' => ['Authorization' => 'Bearer abc123'],
                'event' => [
                    'payload' => ['email' => 'customer@example.com'],
                    'safe_status' => 'failed',
                ],
            ],
        );

        $processed = (new SensitiveDataProcessor)($record);

        $this->assertStringNotContainsString('top-secret', $processed->message);
        $this->assertStringNotContainsString('abc123', $processed->message);
        $this->assertSame('[redacted]', $processed->context['access_token']);
        $this->assertSame('[redacted]', $processed->context['headers']);
        $this->assertSame('[redacted]', $processed->context['event']['payload']);
        $this->assertSame('failed', $processed->context['event']['safe_status']);
    }
}
