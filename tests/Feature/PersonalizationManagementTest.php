<?php

namespace Tests\Feature;

use App\Enums\PersonalizationComponentStatus;
use App\Models\Organization;
use App\Models\PersonalizationRecommendationComponent;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\PersonalizationSmartCartSetting;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Personalization\PersonalizationCheckoutService;
use App\Services\Personalization\PersonalizationConfigurationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PersonalizationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'personalization.environment' => 'test',
            'personalization.denied_shop_domains' => ['macfoxebike.myshopify.com'],
        ]);
    }

    public function test_management_page_is_store_scoped_and_exposes_real_configuration_and_preview_products(): void
    {
        [$operator, $organization, $store] = $this->context('operator');
        $product = $this->product($organization, $store, 101, 'Preview Bike');
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $operator, ['name' => 'Manual', 'algorithm' => 'manual']);
        $service->replaceProductOverrides($store, $strategy, $operator, [[
            'shopify_product_id' => 'gid://shopify/Product/101',
            'type' => 'manual',
        ]]);
        $component = $service->createComponent($store, $strategy, $operator, [
            'name' => 'Product recommendations',
            'placement' => 'product_page',
            'heading' => 'You may also like',
        ]);
        app(PersonalizationCheckoutService::class)->saveThankYou($store, $operator, [
            'strategy_uuid' => $strategy->uuid,
            'heading' => 'Thank you recommendations',
        ]);
        app(PersonalizationCheckoutService::class)->saveOrderStatus($store, $operator, [
            'strategy_uuid' => $strategy->uuid,
            'heading' => 'Order status recommendations',
        ]);

        $this->actingAs($operator)
            ->get(route('personalization.index', [$organization, $store]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Personalization/Index')
                ->where('organization.id', $organization->id)
                ->where('store.id', $store->id)
                ->where('strategies.0.uuid', $strategy->uuid)
                ->where('strategies.0.algorithm', 'manual')
                ->where('components', fn ($components): bool => collect($components)->contains(
                    fn (array $row): bool => $row['uuid'] === $component->uuid && $row['status'] === 'draft',
                ))
                ->where('products.0.shopify_product_id', (string) $product->shopify_product_id)
                ->where('products.0.availability_label', 'Enabled')
                ->where('products.0.available_for_sale', true)
                ->where('permissions.manage', true)
                ->where('permissions.manageSmartCart', false)
                ->where('checkout.thank_you.strategy_uuid', $strategy->uuid)
                ->where('checkout.thank_you.heading', 'Thank you recommendations')
                ->where('checkout.order_status.strategy_uuid', $strategy->uuid)
                ->where('checkout.order_status.heading', 'Order status recommendations')
                ->where('analytics.impressions', 0)
                ->has('options.algorithms', 14)
                ->has('options.placements', 7));

        $preview = $this->actingAs($operator)
            ->getJson(route('personalization.components.preview', [$organization, $store, $component], false));
        $preview->assertOk()
            ->assertJsonPath('data.component.uuid', $component->uuid)
            ->assertJsonPath('data.items.0.shopify_product_id', '101')
            ->assertJsonMissingPath('data.items.0.description');

        $from = now($store->timezone)->subDays(6)->toDateString();
        $to = now($store->timezone)->toDateString();
        $this->actingAs($operator)
            ->get(route('personalization.index', [$organization, $store])."?from={$from}&to={$to}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('analytics.period.from', $from)
                ->where('analytics.period.to', $to)
                ->where('analytics.period.days', 7));
    }

    public function test_store_admin_can_manage_drafts_activation_style_reset_and_smart_cart_safety(): void
    {
        [$admin, $organization, $store] = $this->context('store-admin');
        $this->product($organization, $store, 201, 'Manual Bike');
        $base = route('personalization.index', [$organization, $store], false);

        $this->actingAs($admin)->post("{$base}/strategies", [
            'name' => 'Manual strategy',
            'algorithm' => 'manual',
            'item_limit' => 8,
        ])->assertRedirect();
        $strategy = PersonalizationRecommendationStrategy::query()->sole();

        $this->actingAs($admin)->put("{$base}/strategies/{$strategy->uuid}/rules", [
            'include_tags' => ['Bike'],
            'exclude_tags' => ['Clearance'],
            'minimum_price' => 10,
            'maximum_price' => 1000,
            'minimum_inventory' => null,
            'in_stock_only' => true,
        ])->assertRedirect();
        $this->assertDatabaseCount('personalization_strategy_rules', 5);

        $this->actingAs($admin)->post("{$base}/components", [
            'strategy_uuid' => $strategy->uuid,
            'name' => 'Homepage recommendations',
            'placement' => 'homepage',
            'heading' => 'Recommended',
            'button_label' => 'Add',
        ])->assertRedirect();
        $component = PersonalizationRecommendationComponent::query()->sole();

        $this->actingAs($admin)->post("{$base}/components/{$component->uuid}/activate")
            ->assertRedirect()
            ->assertSessionHas('error', 'A manual recommendation strategy requires at least one product or collection.');
        $this->assertSame(PersonalizationComponentStatus::Draft, $component->fresh()->status);

        $this->actingAs($admin)->put("{$base}/strategies/{$strategy->uuid}/products", [
            'manual' => ['gid://shopify/Product/201'],
            'pinned' => [],
            'excluded' => [],
        ])->assertRedirect();
        $this->actingAs($admin)->post("{$base}/components/{$component->uuid}/activate")
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(PersonalizationComponentStatus::Active, $component->fresh()->status);
        $this->assertTrue($strategy->fresh()->enabled);

        $this->actingAs($admin)->put("{$base}/components/{$component->uuid}/style", [
            'layout' => 'grid',
            'desktop_columns' => 4,
            'mobile_columns' => 2,
            'show_image' => true,
            'show_vendor' => false,
            'show_price' => true,
            'show_compare_at_price' => true,
            'show_add_to_cart' => true,
            'tokens' => [
                'text_color' => '#111111',
                'background_color' => '#FFFFFF',
                'button_color' => '#111111',
                'button_text_color' => '#FFFFFF',
                'border_radius' => 12,
                'gap' => 16,
            ],
        ])->assertRedirect();
        $this->assertSame(PersonalizationComponentStatus::Draft, $component->fresh()->status);
        $this->assertTrue($strategy->fresh()->enabled);

        $this->actingAs($admin)->put("{$base}/smart-cart", [
            'strategy_uuid' => $strategy->uuid,
            'heading' => 'Cart recommendations',
        ])->assertRedirect()->assertSessionHas('success', 'Smart Cart strategy saved and assigned to the native cart drawer.');
        $smartCart = PersonalizationSmartCartSetting::query()->sole();
        $this->assertTrue($smartCart->enabled);
        $this->assertSame('unchecked', $smartCart->compatibility_status->value);
        $this->assertSame('native_cart', $smartCart->fallback_mode);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'personalization_component_activated',
            'store_id' => $store->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_viewer_and_cross_store_models_are_rejected_by_backend_rbac_and_scope(): void
    {
        [$admin, $organization, $store] = $this->context('organization-admin');
        $strategy = app(PersonalizationConfigurationService::class)->createStrategy($store, $admin, [
            'name' => 'Scoped strategy',
            'algorithm' => 'new_arrivals',
        ]);
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store',
            'shopify_domain' => 'other-management.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $otherStore->members()->attach($admin, ['status' => 'active', 'joined_at' => now()]);

        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $viewerRole = Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->sole();
        $viewer->roles()->attach($viewerRole, ['organization_id' => $organization->id, 'store_id' => null]);

        $this->actingAs($viewer)
            ->get(route('personalization.index', [$organization, $store]))
            ->assertForbidden();

        $otherBase = route('personalization.index', [$organization, $otherStore], false);
        $this->actingAs($admin)->put("{$otherBase}/strategies/{$strategy->uuid}", [
            'name' => 'Cross-store overwrite',
            'algorithm' => 'manual',
            'item_limit' => 8,
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertSame('Scoped strategy', $strategy->fresh()->name);
    }

    public function test_widget_editor_is_store_scoped_and_persists_aftership_style_fields(): void
    {
        [$admin, $organization, $store] = $this->context('store-admin');
        $this->product($organization, $store, 701, 'Editor Bike');
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $admin, ['name' => 'Editor strategy', 'algorithm' => 'best_seller']);
        $component = $service->createComponent($store, $strategy, $admin, [
            'name' => 'Cart drawer recommendations', 'placement' => 'cart_page',
            'heading' => 'Exciting additions for you', 'button_label' => 'Add',
        ]);

        $this->actingAs($admin)->get(route('personalization.editor', [$organization, $store]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Personalization/Editor')
            ->where('components.0.uuid', $component->uuid)
            ->where('components.0.strategy_name', 'Editor strategy')
            ->where('products.0.title', 'Editor Bike')
            ->where('products.0.image_url', 'https://cdn.shopify.com/701.jpg')
            ->where('products.0.price', '100.0000')
            ->where('products.0.compare_at_price', null)
            ->where('products.0.currency', 'USD')
            ->where('permissions.manage', true));

        $this->actingAs($admin)->put(route('personalization.editor.update', [$organization, $store, $component]), [
            'strategy_uuid' => $strategy->uuid, 'name' => $component->name, 'placement' => 'cart_page',
            'heading' => 'Recommended for you', 'button_label' => 'Add to cart',
            'enabled' => false,
            'style' => [
                'layout' => 'grid', 'desktop_columns' => 3, 'mobile_columns' => 1,
                'show_image' => true, 'show_vendor' => false, 'show_price' => true,
                'show_compare_at_price' => true, 'show_add_to_cart' => true,
                'tokens' => [
                    'show_product_name' => true, 'show_description' => true,
                    'discount_text' => '(Save *|DISCOUNT|*)', 'comparison_source' => 'compare_at_price',
                    'content_alignment' => 'center', 'variant_display' => 'dynamic',
                    'title_font_size_desktop' => 16, 'button_font_weight' => 400,
                    'custom_css' => 'letter-spacing: 0.01em;',
                ],
            ],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('Recommended for you', $component->fresh()->heading);
        $this->assertSame('center', $component->style()->sole()->tokens['content_alignment']);
        $this->assertSame('letter-spacing: 0.01em;', $component->style()->sole()->tokens['custom_css']);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create([
            'name' => 'Personalization Management',
            'code' => 'personalization-management-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Personalization Store',
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
}
