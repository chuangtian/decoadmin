<?php

namespace Tests\Feature;

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

    public function test_selecting_a_custom_strategy_immediately_enables_native_cart_recommendations(): void
    {
        [$admin, $organization, $store] = $this->context('store-admin');
        $cartProduct = $this->product($organization, $store, 700, 'Cart Bike');
        $recommended = $this->product($organization, $store, 701, 'Smart Cart Accessory');
        $service = app(PersonalizationConfigurationService::class);
        $ruleId = (string) Str::uuid();
        $strategy = $service->createStrategy($store, $admin, [
            'name' => 'Custom native cart strategy',
            'algorithm' => 'manual',
            'item_limit' => 24,
            'settings' => [
                'recommendation_rule' => [
                    'mode' => 'custom',
                    'preset' => 'manual',
                    'custom' => [
                        'rules' => [[
                            'id' => $ruleId,
                            'name' => 'Bike accessory rule',
                            'priority' => 1,
                            'match' => 'all',
                            'conditions' => [[
                                'id' => (string) Str::uuid(),
                                'field' => 'cart_product_ids',
                                'operator' => 'contains_any',
                                'values' => [(string) $cartProduct->shopify_product_id],
                            ]],
                            'exit_on_match' => true,
                            'action' => [
                                'type' => 'manual',
                                'products' => [[
                                    'shopify_product_id' => (string) $recommended->shopify_product_id,
                                    'minimum_quantity' => 2,
                                ]],
                                'filters' => [],
                            ],
                        ]],
                        'fallback' => ['enabled' => false, 'action' => ['type' => 'manual', 'products' => [], 'filters' => []]],
                    ],
                ],
                'discount' => [
                    'enabled' => true,
                    'reference' => 'gid://shopify/DiscountCodeNode/123',
                    'title' => 'Ten percent off',
                    'summary' => '10% off',
                    'code' => 'SMART10',
                    'status' => 'active',
                    'percentage' => 10,
                    'validated_at' => now()->toIso8601String(),
                ],
            ],
        ]);
        $base = route('personalization.index', [$organization, $store], false);

        $this->actingAs($admin)->put("{$base}/smart-cart", [
            'strategy_uuid' => $strategy->uuid,
            'heading' => 'Chosen with care',
        ])->assertRedirect()->assertSessionHas('success', 'Smart Cart 策略已保存，并用于原生购物车抽屉。');

        $setting = PersonalizationSmartCartSetting::query()->sole();
        $this->assertTrue($setting->enabled);
        $this->assertSame('native_cart', $setting->fallback_mode);
        $this->assertSame('native_cart_embed', data_get($setting->compatibility_details, 'mode'));
        $this->assertTrue($strategy->fresh()->enabled);
        $this->assertSame('enabled', $strategy->fresh()->status->value);

        $this->getJson($this->signedSmartCartUrl($store, [
            'cart_product_ids' => (string) $cartProduct->shopify_product_id,
        ]))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.fallback_mode', 'native_cart')
            ->assertJsonPath('data.heading', 'Chosen with care')
            ->assertJsonPath('data.recommendations.items.0.shopify_product_id', (string) $recommended->shopify_product_id)
            ->assertJsonPath('data.recommendations.items.0.minimum_purchase_quantity', 2)
            ->assertJsonPath('data.recommendations.items.0.rule_id', $ruleId)
            ->assertJsonPath('data.recommendations.items.0.pricing.original_amount', '100.00')
            ->assertJsonPath('data.recommendations.items.0.pricing.discounted_amount', '90.00')
            ->assertJsonPath('data.recommendations.discount.code', 'SMART10')
            ->assertJsonMissingPath('data.customer');

        $this->getJson($this->signedSmartCartUrl($store))
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonCount(0, 'data.recommendations.items');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'personalization_smart_cart_configuration_saved',
            'store_id' => $store->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_clearing_selection_hides_recommendations_and_old_activation_routes_are_removed(): void
    {
        [$admin, $organization, $store] = $this->context('store-admin');
        $product = $this->product($organization, $store, 801, 'Manual accessory');
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $admin, ['name' => 'Manual', 'algorithm' => 'manual']);
        $service->replaceProductOverrides($store, $strategy, $admin, [[
            'shopify_product_id' => 'gid://shopify/Product/'.$product->shopify_product_id,
            'type' => 'manual',
        ]]);
        $base = route('personalization.index', [$organization, $store], false);

        $this->actingAs($admin)->put("{$base}/smart-cart", [
            'strategy_uuid' => $strategy->uuid,
            'heading' => 'Recommended',
        ])->assertRedirect();
        $this->actingAs($admin)->put("{$base}/smart-cart", [
            'strategy_uuid' => null,
            'heading' => 'Recommended',
        ])->assertRedirect()->assertSessionHas('success', 'Smart Cart 策略已清除；原生购物车不显示推荐。');

        $setting = PersonalizationSmartCartSetting::query()->sole();
        $this->assertFalse($setting->enabled);
        $this->assertNull($setting->strategy_id);
        $this->getJson($this->signedSmartCartUrl($store))
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.fallback_mode', 'native_cart')
            ->assertJsonMissingPath('data.recommendations');

        foreach (['compatibility', 'preview-confirmation', 'activate', 'restore'] as $route) {
            $this->actingAs($admin)->post("{$base}/smart-cart/{$route}")->assertNotFound();
        }
    }

    public function test_invalid_or_unauthorized_smart_cart_binding_is_rejected(): void
    {
        [$admin, $organization, $store] = $this->context('store-admin');
        $service = app(PersonalizationConfigurationService::class);
        $emptyCustom = $service->createStrategy($store, $admin, [
            'name' => 'Empty custom',
            'algorithm' => 'manual',
            'settings' => ['recommendation_rule' => [
                'mode' => 'custom', 'preset' => 'manual',
                'custom' => ['rules' => [], 'fallback' => ['enabled' => false, 'action' => ['type' => 'manual', 'products' => [], 'filters' => []]]],
            ]],
        ]);
        $base = route('personalization.index', [$organization, $store], false);
        $this->actingAs($admin)->put("{$base}/smart-cart", [
            'strategy_uuid' => $emptyCustom->uuid,
            'heading' => 'Invalid',
        ])->assertRedirect()->assertSessionHas('error', '自定义规则至少需要一个行动商品或备用商品。');

        [$operator, $operatorOrganization, $operatorStore] = $this->context('operator');
        $operatorBase = route('personalization.index', [$operatorOrganization, $operatorStore], false);
        $this->actingAs($operator)->put("{$operatorBase}/smart-cart", [
            'strategy_uuid' => null,
            'heading' => 'Forbidden',
        ])->assertForbidden();
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
            'store_id' => $store->id,
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
