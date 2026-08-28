<?php

namespace Tests\Feature;

use App\Exceptions\PersonalizationException;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PersonalizationAttribution;
use App\Models\PersonalizationDailyMetric;
use App\Models\PersonalizationEvent;
use App\Models\PersonalizationEventSource;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\PersonalizationSmartCartSetting;
use App\Models\Product;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\WebhookEvent;
use App\Services\Personalization\PersonalizationDataLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationDataLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'personalization.environment' => 'test',
            'personalization.active.handle' => 'deco-personalization-test',
            'personalization.retention.raw_event_days' => 90,
            'personalization.retention.attribution_days' => 90,
            'personalization.retention.aggregate_months' => 13,
            'personalization.retention.audit_days' => 365,
            'personalization.retention.uninstall_purge_hours' => 48,
        ]);
    }

    public function test_retention_rolls_up_before_pruning_raw_detail_and_keeps_unrelated_audits(): void
    {
        [$organization, $store] = $this->context('Retention');
        $source = $this->source($organization, $store);
        $oldAt = now()->subDays(91)->startOfHour();
        $oldImpression = $this->event($source, 'old-impression', 'deco_personalization:impression', $oldAt, 'homepage');
        $oldClick = $this->event($source, 'old-click', 'deco_personalization:click', $oldAt->copy()->addMinute(), 'homepage');
        $recent = $this->event($source, 'recent-click', 'deco_personalization:click', now()->subDays(10), 'homepage');
        $order = $this->order($organization, $store, 3001, 100);
        $order->forceFill(['processed_at' => $oldAt, 'created_at_shopify' => $oldAt])->save();
        PersonalizationAttribution::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'order_id' => $order->id,
            'click_event_id' => $oldClick->id,
            'placement' => 'homepage',
            'model' => 'last_recommendation_click',
            'window_days' => 7,
            'status' => 'partially_refunded',
            'currency' => 'USD',
            'gross_revenue' => 100,
            'refund_amount' => 20,
            'attributed_revenue' => 80,
            'clicked_at' => $oldClick->occurred_at,
            'ordered_at' => $oldAt,
            'reconciled_at' => now(),
        ]);
        PersonalizationDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'metric_date' => now()->subMonthsNoOverflow(14)->toDateString(),
            'placement' => 'homepage',
            'component_key' => '',
            'strategy_key' => '',
            'currency' => 'USD',
            'impressions' => 10,
        ]);
        $personalizationAudit = AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'action' => 'personalization_old_audit',
            'subject_type' => Store::class,
            'subject_id' => $store->id,
        ]);
        $personalizationAudit->forceFill(['created_at' => now()->subDays(366)])->save();
        $unrelatedAudit = AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'action' => 'shopify_unrelated_old_audit',
            'subject_type' => Store::class,
            'subject_id' => $store->id,
        ]);
        $unrelatedAudit->forceFill(['created_at' => now()->subDays(500)])->save();

        $this->artisan('personalization:prune-data')
            ->expectsOutputToContain('events: 2; attributions: 1; aggregates: 1; audits: 1')
            ->assertSuccessful();

        $this->assertDatabaseMissing('personalization_events', ['id' => $oldImpression->id]);
        $this->assertDatabaseMissing('personalization_events', ['id' => $oldClick->id]);
        $this->assertDatabaseHas('personalization_events', ['id' => $recent->id]);
        $this->assertDatabaseCount('personalization_attributions', 0);
        $metric = PersonalizationDailyMetric::query()
            ->where('store_id', $store->id)
            ->whereDate('metric_date', $oldAt->toDateString())
            ->where('placement', 'homepage')
            ->sole();
        $this->assertSame(1, $metric->impressions);
        $this->assertSame(1, $metric->clicks);
        $this->assertSame(1, $metric->orders);
        $this->assertSame('80.0000', $metric->attributed_revenue);
        $this->assertDatabaseMissing('audit_logs', ['id' => $personalizationAudit->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $unrelatedAudit->id]);
    }

    public function test_due_uninstall_purge_removes_only_personalization_online_data(): void
    {
        [$organization, $store] = $this->context('Purge');
        $product = Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => 4001,
            'title' => 'Shared Product',
            'handle' => 'shared-product',
            'status' => 'active',
            'tags' => [],
            'published_at_shopify' => now(),
            'synced_at' => now(),
        ]);
        $order = $this->order($organization, $store, 4002, 120);
        $source = $this->source($organization, $store);
        $source->forceFill(['purge_after' => now()->subMinute(), 'status' => 'inactive'])->save();
        $event = $this->event($source, 'purge-event', 'deco_personalization:click', now(), 'homepage');
        $strategy = PersonalizationRecommendationStrategy::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'name' => 'Purge Strategy',
            'algorithm' => 'new_arrivals',
            'enabled' => true,
            'item_limit' => 8,
        ]);
        $component = PersonalizationRecommendationComponent::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'strategy_id' => $strategy->id,
            'name' => 'Purge Component',
            'placement' => 'homepage',
            'status' => 'active',
            'position' => 1,
        ]);
        PersonalizationSmartCartSetting::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'strategy_id' => $strategy->id,
            'enabled' => false,
            'compatibility_status' => 'unchecked',
            'fallback_mode' => 'shopify_default',
        ]);
        PersonalizationDailyMetric::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'metric_date' => now()->toDateString(),
            'placement' => 'homepage',
            'component_key' => $component->uuid,
            'strategy_key' => $strategy->uuid,
            'currency' => 'USD',
            'clicks' => 1,
        ]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'commerce-hub-token',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'connected',
        ]);
        $app = App::query()->create([
            'name' => 'Deco 个性化推荐测试',
            'handle' => 'deco-personalization-test',
            'client_id' => 'personalization-test-client',
            'client_secret_encrypted' => 'personalization-secret',
            'distribution' => 'custom',
            'status' => 'active',
            'scopes' => ['write_pixels'],
        ]);
        $installation = AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'status' => 'uninstalled',
            'granted_scopes' => ['write_pixels'],
            'access_token_encrypted' => 'personalization-token',
            'refresh_token_encrypted' => 'personalization-refresh',
            'settings' => ['source' => 'test'],
            'installed_at' => now()->subDay(),
            'uninstalled_at' => now(),
        ]);
        $webhook = WebhookEvent::query()->create([
            'webhook_id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'app_id' => $app->id,
            'topic' => 'app/uninstalled',
            'headers' => ['shop_domain' => $store->shopify_domain],
            'payload' => ['storage' => 'encrypted'],
            'payload_encrypted' => '{"id":1}',
            'payload_sha256' => hash('sha256', '{"id":1}'),
            'status' => 'processed',
            'attempts' => 1,
            'received_at' => now(),
        ]);

        $this->artisan('personalization:prune-data --due-only')
            ->expectsOutput('Purged 1 due Personalization stores.')
            ->assertSuccessful();

        $this->assertDatabaseCount('personalization_event_sources', 0);
        $this->assertDatabaseCount('personalization_events', 0);
        $this->assertDatabaseCount('personalization_daily_metrics', 0);
        $this->assertDatabaseCount('personalization_recommendation_components', 0);
        $this->assertDatabaseCount('personalization_recommendation_strategies', 0);
        $this->assertDatabaseCount('personalization_smart_cart_settings', 0);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertSame('commerce-hub-token', $connection->fresh()->access_token_encrypted);
        $installation->refresh();
        $this->assertNull($installation->access_token_encrypted);
        $this->assertNull($installation->refresh_token_encrypted);
        $this->assertNull($installation->settings);
        $webhook->refresh();
        $this->assertNull($webhook->payload_encrypted);
        $this->assertSame(['storage' => 'redacted'], $webhook->payload);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'personalization_online_data_purged',
            'store_id' => $store->id,
        ]);
        $this->assertDatabaseMissing('personalization_events', ['id' => $event->id]);

        $this->artisan('schedule:list')
            ->expectsOutputToContain('personalization:prune-data --due-only')
            ->expectsOutputToContain('personalization:prune-data')
            ->assertSuccessful();
    }

    public function test_retention_and_purge_fail_closed_for_permanent_denied_shop(): void
    {
        [$organization, $store] = $this->context('Denied Purge');
        $store->forceFill(['shopify_domain' => 'macfoxebike.myshopify.com'])->save();
        $source = $this->source($organization, $store);
        $source->forceFill(['status' => 'inactive', 'purge_after' => now()->subMinute()])->save();

        try {
            app(PersonalizationDataLifecycleService::class)->purgeDueStores();
            $this->fail('Denied shop purge must fail closed.');
        } catch (PersonalizationException $exception) {
            $this->assertSame('SHOP_WRITE_DENIED', $exception->errorCode);
        }
        $this->assertDatabaseHas('personalization_event_sources', ['id' => $source->id]);
    }

    /** @return array{Organization, Store} */
    private function context(string $name): array
    {
        $organization = Organization::query()->create([
            'name' => $name,
            'code' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $store = $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => Str::lower(Str::random(12)).'.myshopify.com',
            'status' => 'active',
            'timezone' => 'UTC',
            'currency' => 'USD',
        ]);

        return [$organization, $store];
    }

    private function source(Organization $organization, Store $store): PersonalizationEventSource
    {
        return PersonalizationEventSource::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'status' => 'active',
            'activated_at' => now(),
        ]);
    }

    private function event(
        PersonalizationEventSource $source,
        string $id,
        string $name,
        mixed $occurredAt,
        string $placement,
    ): PersonalizationEvent {
        return PersonalizationEvent::query()->create([
            'organization_id' => $source->organization_id,
            'store_id' => $source->store_id,
            'event_source_id' => $source->id,
            'event_id' => $id,
            'event_name' => $name,
            'client_id_hash' => hash('sha256', $id.'-client'),
            'session_id_hash' => hash('sha256', $id.'-session'),
            'payload_hash' => hash('sha256', $id.'-payload'),
            'placement' => $placement,
            'occurred_at' => $occurredAt,
            'received_at' => now(),
        ]);
    }

    private function order(Organization $organization, Store $store, int $shopifyId, float $total): Order
    {
        return Order::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_order_id' => $shopifyId,
            'order_number' => '#'.$shopifyId,
            'financial_status' => 'paid',
            'currency' => 'USD',
            'total_price' => $total,
            'subtotal_price' => $total,
            'net_sales' => $total,
            'discount_total' => 0,
            'refund_total' => 0,
            'shipping_total' => 0,
            'total_tax' => 0,
            'is_test' => false,
            'processed_at' => now(),
            'created_at_shopify' => now(),
            'synced_at' => now(),
        ]);
    }
}
