<?php

namespace Tests\Feature;

use App\Enums\PersonalizationSmartCartCompatibilityStatus;
use App\Models\Organization;
use App\Models\PersonalizationSmartCartSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Personalization\PersonalizationConfigurationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationSmartCartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'personalization.environment' => 'test',
            'personalization.active.client_secret' => 'personalization-proxy-secret',
            'personalization.active_proxy_path' => '/apps/deco-personalization-test',
            'personalization.denied_shop_domains' => ['macfoxebike.myshopify.com'],
        ]);
    }

    public function test_smart_cart_requires_compatibility_preview_and_manual_activation_then_restores_native_cart(): void
    {
        [$admin, $organization, $store] = $this->context('store-admin');
        $this->product($organization, $store, 701, 'Smart Cart Bike');
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $admin, [
            'name' => 'Smart Cart manual',
            'algorithm' => 'manual',
        ]);
        $service->replaceProductOverrides($store, $strategy, $admin, [[
            'shopify_product_id' => 'gid://shopify/Product/701',
            'type' => 'manual',
        ]]);
        $base = route('personalization.index', [$organization, $store], false);

        $this->actingAs($admin)->put("{$base}/smart-cart", [
            'strategy_uuid' => $strategy->uuid,
            'heading' => 'Complete the set',
        ])->assertRedirect()->assertSessionHas('success');

        $this->getJson($this->signedSmartCartUrl($store))
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.fallback_mode', 'shopify_default')
            ->assertJsonMissingPath('data.recommendations');

        $this->actingAs($admin)->post("{$base}/smart-cart/activate")
            ->assertRedirect()
            ->assertSessionHas('error', 'Smart Cart 需要最近 7 天内通过兼容性检查。');

        $this->actingAs($admin)->post("{$base}/smart-cart/compatibility", [
            'theme_id' => '123456789',
            'theme_name' => 'Dawn Personalization Test',
            'checks' => $this->checks(false),
        ])->assertRedirect()->assertSessionHas('success');
        $setting = PersonalizationSmartCartSetting::query()->sole();
        $this->assertFalse($setting->enabled);
        $this->assertSame(PersonalizationSmartCartCompatibilityStatus::Incompatible, $setting->compatibility_status);

        $this->actingAs($admin)->post("{$base}/smart-cart/preview-confirmation")
            ->assertRedirect()
            ->assertSessionHas('error', '必须先完成指定测试主题的兼容性检查。');

        $this->actingAs($admin)->post("{$base}/smart-cart/compatibility", [
            'theme_id' => '123456789',
            'theme_name' => 'Dawn Personalization Test',
            'checks' => $this->checks(true),
        ])->assertRedirect()->assertSessionHas('success');
        $setting = $setting->fresh();
        $this->assertFalse($setting->enabled);
        $this->assertSame(PersonalizationSmartCartCompatibilityStatus::Compatible, $setting->compatibility_status);
        $this->assertNull($setting->preview_confirmed_at);

        $this->actingAs($admin)->post("{$base}/smart-cart/preview-confirmation")
            ->assertRedirect()->assertSessionHas('success');
        $this->assertFalse($setting->fresh()->enabled);
        $this->assertNotNull($setting->fresh()->preview_confirmed_at);

        $this->actingAs($admin)->post("{$base}/smart-cart/activate")
            ->assertRedirect()->assertSessionHas('success', 'Smart Cart 已人工启用。');
        $setting = $setting->fresh();
        $this->assertTrue($setting->enabled);
        $this->assertNotNull($setting->enabled_at);
        $this->assertNull($setting->disabled_at);
        $this->assertTrue($strategy->fresh()->enabled);

        $this->getJson($this->signedSmartCartUrl($store, ['cart_product_ids' => ['999']]))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.fallback_mode', 'shopify_default')
            ->assertJsonPath('data.heading', 'Complete the set')
            ->assertJsonPath('data.recommendations.items.0.shopify_product_id', '701')
            ->assertJsonMissingPath('data.customer');

        $this->actingAs($admin)->post("{$base}/smart-cart/restore")
            ->assertRedirect()->assertSessionHas('success', '已恢复 Shopify 默认购物车。');
        $setting = $setting->fresh();
        $this->assertFalse($setting->enabled);
        $this->assertNull($setting->enabled_at);
        $this->assertNotNull($setting->disabled_at);

        $this->getJson($this->signedSmartCartUrl($store))
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonMissingPath('data.recommendations');
        foreach ([
            'personalization_smart_cart_compatibility_recorded',
            'personalization_smart_cart_preview_confirmed',
            'personalization_smart_cart_activated',
            'personalization_smart_cart_restored',
        ] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'store_id' => $store->id,
                'user_id' => $admin->id,
            ]);
        }
    }

    public function test_stale_compatibility_and_operator_permission_cannot_enable_smart_cart(): void
    {
        [$admin, $organization, $store] = $this->context('store-admin');
        $strategy = app(PersonalizationConfigurationService::class)->createStrategy($store, $admin, [
            'name' => 'New arrivals',
            'algorithm' => 'new_arrivals',
        ]);
        $base = route('personalization.index', [$organization, $store], false);
        $this->actingAs($admin)->put("{$base}/smart-cart", [
            'strategy_uuid' => $strategy->uuid,
            'heading' => 'New arrivals',
        ])->assertRedirect();
        $this->actingAs($admin)->post("{$base}/smart-cart/compatibility", [
            'theme_id' => '987654321',
            'theme_name' => 'Dawn Test Copy',
            'checks' => [$this->checks(true)[0]],
        ])->assertRedirect()->assertSessionHas('error', '兼容性检查缺少必填项目。');
        $this->actingAs($admin)->post("{$base}/smart-cart/compatibility", [
            'theme_id' => '987654321',
            'theme_name' => 'Dawn Test Copy',
            'checks' => $this->checks(true),
        ])->assertRedirect();
        $this->actingAs($admin)->post("{$base}/smart-cart/preview-confirmation")->assertRedirect();
        PersonalizationSmartCartSetting::query()->sole()->forceFill([
            'compatibility_checked_at' => now()->subDays(8),
        ])->save();

        $this->actingAs($admin)->post("{$base}/smart-cart/activate")
            ->assertRedirect()
            ->assertSessionHas('error', 'Smart Cart 需要最近 7 天内通过兼容性检查。');
        $this->assertFalse(PersonalizationSmartCartSetting::query()->sole()->enabled);

        [$operator, $operatorOrganization, $operatorStore] = $this->context('operator');
        $operatorBase = route('personalization.index', [$operatorOrganization, $operatorStore], false);
        $this->actingAs($operator)->put("{$operatorBase}/smart-cart", [
            'strategy_uuid' => null,
            'heading' => 'Forbidden',
        ])->assertForbidden();
        $this->actingAs($operator)->post("{$operatorBase}/smart-cart/activate")->assertForbidden();
    }

    /** @return list<array{key: string, label: string, passed: bool, details: ?string}> */
    private function checks(bool $passed): array
    {
        return [
            ['key' => 'unpublished_copy', 'label' => 'Unpublished test copy', 'passed' => true, 'details' => null],
            ['key' => 'app_embed_loaded', 'label' => 'App Embed loaded', 'passed' => true, 'details' => null],
            ['key' => 'browser_dialog', 'label' => 'Browser dialog support', 'passed' => true, 'details' => null],
            ['key' => 'cart_link', 'label' => 'Theme cart link detected', 'passed' => true, 'details' => null],
            ['key' => 'cart_routes', 'label' => 'Shopify cart routes available', 'passed' => true, 'details' => null],
            ['key' => 'cart_behaviour_verified', 'label' => 'Cart behaviour verified', 'passed' => $passed, 'details' => null],
        ];
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create([
            'name' => 'Smart Cart '.Str::random(6),
            'code' => 'smart-cart-'.Str::lower(Str::random(8)),
            'status' => 'active',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Smart Cart Store',
            'shopify_domain' => Str::lower(Str::random(12)).'.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->sole();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => in_array($roleSlug, ['store-admin', 'operator'], true) ? $store->id : null,
        ]);

        return [$user, $organization, $store];
    }

    private function product(Organization $organization, Store $store, int $shopifyId, string $title): Product
    {
        $product = Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $shopifyId,
            'title' => $title,
            'handle' => Str::slug($title),
            'status' => 'active',
            'vendor' => 'Deco',
            'product_type' => 'Bike',
            'tags' => ['Bike'],
            'published_at_shopify' => now(),
            'online_store_url' => 'https://example.invalid/products/'.Str::slug($title),
            'featured_image_url' => 'https://cdn.shopify.com/'.$shopifyId.'.jpg',
            'synced_at' => now(),
        ]);
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'shopify_variant_id' => $shopifyId * 10,
            'title' => 'Default',
            'price' => 100,
            'available_for_sale' => true,
            'selected_options' => [],
        ]);

        return $product;
    }

    /** @param array<string, mixed> $overrides */
    private function signedSmartCartUrl(Store $store, array $overrides = []): string
    {
        $parameters = [
            'shop' => $store->shopify_domain,
            'timestamp' => now()->timestamp,
            'path_prefix' => '/apps/deco-personalization-test',
            ...$overrides,
        ];
        $pieces = [];
        foreach ($parameters as $key => $value) {
            $values = is_array($value) ? $value : [$value];
            $pieces[] = $key.'='.implode(',', array_map('strval', $values));
        }
        sort($pieces, SORT_STRING);
        $parameters['signature'] = hash_hmac('sha256', implode('', $pieces), 'personalization-proxy-secret');

        return route('personalization.public.smart-cart', [], false).'?'.http_build_query($parameters);
    }
}
