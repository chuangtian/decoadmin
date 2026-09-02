<?php

namespace Tests\Feature;

use App\Enums\PersonalizationAlgorithm;
use App\Enums\PersonalizationComponentStatus;
use App\Enums\PersonalizationPlacement;
use App\Enums\PersonalizationSmartCartCompatibilityStatus;
use App\Exceptions\PersonalizationException;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Personalization\PersonalizationConfigurationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationConfigurationTest extends TestCase
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

    public function test_store_admin_can_build_a_bounded_store_scoped_configuration(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $productOne = $this->product($organization, $store, 101, 'City Bike');
        $productTwo = $this->product($organization, $store, 102, 'Bike Helmet');
        $service = app(PersonalizationConfigurationService::class);

        $strategy = $service->createStrategy($store, $actor, [
            'name' => '商品页相似推荐',
            'algorithm' => PersonalizationAlgorithm::SimilarProducts->value,
            'item_limit' => 6,
        ]);
        $strategy = $service->replaceRules($store, $strategy, $actor, [
            ['type' => 'include_tags', 'value' => ['Bike', 'Featured']],
            ['type' => 'minimum_price', 'value' => '50'],
            ['type' => 'maximum_price', 'value' => '5000'],
            ['type' => 'minimum_inventory', 'value' => 1],
            ['type' => 'in_stock_only', 'value' => true],
        ]);
        $strategy = $service->replaceProductOverrides($store, $strategy, $actor, [
            ['shopify_product_id' => 'gid://shopify/Product/101', 'type' => 'pinned'],
            ['shopify_product_id' => 'gid://shopify/Product/102', 'type' => 'excluded'],
        ]);
        $component = $service->createComponent($store, $strategy, $actor, [
            'name' => '商品页推荐组件',
            'placement' => PersonalizationPlacement::ProductPage->value,
            'heading' => '你可能还喜欢',
            'button_label' => '加入购物车',
        ]);
        $style = $service->updateStyle($store, $component, $actor, [
            'layout' => 'grid',
            'desktop_columns' => 4,
            'mobile_columns' => 2,
            'show_vendor' => true,
            'tokens' => [
                'text_color' => '#111111',
                'background_color' => '#FFFFFF',
                'border_radius' => 12,
                'gap' => 16,
            ],
        ]);
        $smartCart = $service->saveSmartCartDraft($store, $actor, $strategy, [
            'heading' => '购物车推荐',
        ]);
        $configuration = $service->configuration($store, $actor);

        $this->assertTrue($strategy->enabled);
        $this->assertSame(PersonalizationAlgorithm::SimilarProducts, $strategy->algorithm);
        $this->assertSame('50.00', $strategy->rules->firstWhere('type', 'minimum_price')->value['amount']);
        $this->assertSame($productOne->id, $strategy->productOverrides->firstWhere('type', 'pinned')->product_id);
        $this->assertSame($productTwo->id, $strategy->productOverrides->firstWhere('type', 'excluded')->product_id);
        $this->assertSame(PersonalizationComponentStatus::Draft, $component->status);
        $this->assertSame(PersonalizationPlacement::ProductPage, $component->placement);
        $this->assertSame('grid', $style->layout);
        $this->assertTrue($style->show_vendor);
        $this->assertTrue($smartCart->enabled);
        $this->assertSame(PersonalizationSmartCartCompatibilityStatus::Unchecked, $smartCart->compatibility_status);
        $this->assertSame('native_cart', $smartCart->fallback_mode);
        $this->assertCount(1, $configuration['strategies']);
        $this->assertCount(1, $configuration['components']);
        $this->assertSame($smartCart->id, $configuration['smart_cart']->id);
        $this->assertDatabaseCount('personalization_strategy_rules', 5);
        $this->assertDatabaseCount('personalization_strategy_product_overrides', 2);
        $this->assertDatabaseCount('personalization_component_styles', 1);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'personalization_smart_cart_configuration_saved',
            'store_id' => $store->id,
            'user_id' => $actor->id,
        ]);
    }

    public function test_configuration_supports_exactly_the_confirmed_p0_algorithms_and_placements(): void
    {
        $this->assertSame([
            'manual',
            'next_llm',
            'free_shipping_upsell',
            'similar_products',
            'substitute_products',
            'best_seller',
            'new_arrivals',
            'frequently_bought_together',
            'frequently_viewed_together',
            'complementary_products',
            'recently_viewed',
            'complete_the_look',
            'same_product_upsell',
            'all_products',
        ], array_column(PersonalizationAlgorithm::cases(), 'value'));
        $this->assertSame([
            'homepage',
            'product_page',
            'cart_page',
            'smart_cart',
            'checkout',
            'thank_you',
        ], array_column(PersonalizationPlacement::cases(), 'value'));
    }

    public function test_strategy_children_cannot_cross_store_or_reference_unsynced_products(): void
    {
        [$actor, $organization, $store] = $this->context('organization-admin');
        $otherStore = $organization->stores()->create([
            'name' => 'Other Store',
            'shopify_domain' => 'other-personalization-store.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $otherStore->members()->attach($actor, ['status' => 'active', 'joined_at' => now()]);
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $actor, [
            'name' => 'Store A',
            'algorithm' => 'manual',
        ]);
        $this->product($organization, $otherStore, 999, 'Other Product');

        try {
            $service->createComponent($otherStore, $strategy, $actor, [
                'name' => 'Cross Store',
                'placement' => 'homepage',
            ]);
            $this->fail('Cross-store strategy binding must be rejected.');
        } catch (PersonalizationException $exception) {
            $this->assertSame('STRATEGY_NOT_FOUND', $exception->errorCode);
            $this->assertSame(404, $exception->statusCode);
        }

        try {
            $service->replaceProductOverrides($store, $strategy, $actor, [[
                'shopify_product_id' => 'gid://shopify/Product/999',
                'type' => 'manual',
            ]]);
            $this->fail('A product from another store must be rejected.');
        } catch (PersonalizationException $exception) {
            $this->assertSame('PRODUCT_OVERRIDE_NOT_FOUND', $exception->errorCode);
        }

        $this->assertDatabaseCount('personalization_recommendation_components', 0);
        $this->assertDatabaseCount('personalization_strategy_product_overrides', 0);
    }

    public function test_operator_can_manage_recommendations_but_not_smart_cart_and_viewer_cannot_read(): void
    {
        [$operator, $organization, $store] = $this->context('operator');
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $operator, [
            'name' => '运营推荐',
            'algorithm' => 'best_seller',
        ]);

        try {
            $service->saveSmartCartDraft($store, $operator, $strategy);
            $this->fail('Operator must not manage Smart Cart.');
        } catch (PersonalizationException $exception) {
            $this->assertSame('PERSONALIZATION_ACCESS_DENIED', $exception->errorCode);
            $this->assertSame(403, $exception->statusCode);
        }

        $viewer = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($viewer, ['status' => 'active', 'joined_at' => now()]);
        $viewerRole = Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->firstOrFail();
        $viewer->roles()->attach($viewerRole, [
            'organization_id' => $organization->id,
            'store_id' => null,
        ]);

        try {
            $service->configuration($store, $viewer);
            $this->fail('Viewer must not read Personalization configuration.');
        } catch (PersonalizationException $exception) {
            $this->assertSame('PERSONALIZATION_ACCESS_DENIED', $exception->errorCode);
        }
    }

    public function test_rule_product_and_style_inputs_are_bounded_and_conflict_safe(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $this->product($organization, $store, 101, 'Bike');
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $actor, [
            'name' => 'Validated',
            'algorithm' => 'similar_products',
        ]);

        $this->assertExceptionCode('INVALID_STRATEGY_RULE', fn () => $service->replaceRules($store, $strategy, $actor, [
            ['type' => 'in_stock_only', 'value' => true],
            ['type' => 'in_stock_only', 'value' => false],
        ]));
        $this->assertExceptionCode('CONFLICTING_PRODUCT_OVERRIDE', fn () => $service->replaceProductOverrides($store, $strategy, $actor, [
            ['shopify_product_id' => 'gid://shopify/Product/101', 'type' => 'pinned'],
            ['shopify_product_id' => 'gid://shopify/Product/101', 'type' => 'excluded'],
        ]));

        $component = $service->createComponent($store, $strategy, $actor, [
            'name' => 'Validated component',
            'placement' => 'homepage',
        ]);
        $this->assertExceptionCode('INVALID_STYLE_TOKENS', fn () => $service->updateStyle($store, $component, $actor, [
            'tokens' => ['custom_css' => 'body { display: none }'],
        ]));
        $this->assertExceptionCode('INVALID_DESKTOP_COLUMNS', fn () => $service->updateStyle($store, $component, $actor, [
            'desktop_columns' => 99,
        ]));
    }

    public function test_denylisted_store_cannot_receive_configuration_writes(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $store->forceFill(['shopify_domain' => 'macfoxebike.myshopify.com'])->save();

        $this->assertExceptionCode('SHOP_WRITE_DENIED', fn () => app(PersonalizationConfigurationService::class)
            ->createStrategy($store, $actor, ['name' => 'Denied', 'algorithm' => 'manual']));
        $this->assertDatabaseCount('personalization_recommendation_strategies', 0);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create([
            'name' => 'Personalization Configuration',
            'code' => 'personalization-'.Str::lower(Str::random(8)),
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
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => in_array($roleSlug, ['store-admin', 'operator'], true) ? $store->id : null,
        ]);

        return [$user, $organization, $store];
    }

    private function product(Organization $organization, Store $store, int $shopifyId, string $title): Product
    {
        return Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $shopifyId,
            'title' => $title,
            'handle' => Str::slug($title),
            'status' => 'active',
            'tags' => ['Bike'],
            'synced_at' => now(),
        ]);
    }

    private function assertExceptionCode(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected Personalization exception {$code}.");
        } catch (PersonalizationException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}
