<?php

namespace Tests\Feature;

use App\Enums\PersonalizationPlacement;
use App\Exceptions\PersonalizationException;
use App\Models\Organization;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\Personalization\PersonalizationCheckoutService;
use App\Services\Personalization\PersonalizationConfigurationService;
use App\Services\Personalization\PersonalizationStrategyWorkflowService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalizationCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'personalization.environment' => 'test',
            'personalization.active.client_id' => 'personalization-test-client-id',
            'personalization.active.client_secret' => 'personalization-test-secret',
            'personalization.denied_shop_domains' => ['macfoxebike.myshopify.com'],
        ]);
    }

    public function test_checkout_directly_binds_and_activates_a_store_strategy(): void
    {
        [$admin, $organization, $store] = $this->context('Checkout A');
        $product = $this->product($organization, $store, 101);
        $strategy = $this->checkoutStrategy($store, $admin, $product);
        $service = app(PersonalizationCheckoutService::class);

        $this->assertNull($service->configuration($store, $admin));
        $this->assertFalse($service->storefront($store)['enabled']);
        $this->assertFalse(data_get($service->storefront($store), 'thank_you.enabled'));

        $setting = $service->save($store, $admin, [
            'strategy_uuid' => $strategy->uuid,
            'trust_items' => $service->defaultTrustItems(),
        ]);
        $payload = $service->storefront($store);

        $this->assertTrue($setting->enabled);
        $this->assertNull($setting->shopify_collection_id);
        $this->assertTrue($payload['enabled']);
        $this->assertSame('checkout', data_get($payload, 'component.placement'));
        $this->assertSame($strategy->uuid, data_get($payload, 'component.strategy_uuid'));
        $this->assertSame('active', $setting->component->status->value);
        $this->assertTrue($strategy->refresh()->enabled);
        $this->assertSame('enabled', $strategy->status->value);
        $this->assertSame('ORDER_SUMMARY2', data_get($setting->settings, 'recommendation_placement'));
        $this->assertSame('strategy', data_get($setting->settings, 'candidate_source'));
        $this->assertArrayNotHasKey('collection', $payload);
        $this->assertArrayNotHasKey('sequence', $payload);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'personalization_checkout_configuration_saved',
            'store_id' => $store->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_thank_you_uses_an_independent_strategy_and_falls_back_to_other_available_products(): void
    {
        [$admin, $organization, $store] = $this->context('Thank You A');
        $purchased = $this->product($organization, $store, 401);
        $fallback = $this->product($organization, $store, 402);
        $checkoutProduct = $this->product($organization, $store, 403);
        $checkoutStrategy = $this->checkoutStrategy($store, $admin, $checkoutProduct);
        $strategy = $this->checkoutStrategy($store, $admin, $purchased);
        $service = app(PersonalizationCheckoutService::class);

        $service->save($store, $admin, [
            'strategy_uuid' => $checkoutStrategy->uuid,
            'trust_items' => $service->defaultTrustItems(),
        ]);

        $setting = $service->saveThankYou($store, $admin, [
            'strategy_uuid' => $strategy->uuid,
            'heading' => 'A thank-you offer for you',
        ]);
        $payload = $service->storefront($store);
        $recommendations = $service->recommendations($store, [
            'surface' => 'thank_you',
            'cart_lines' => [[
                'product_id' => (string) $purchased->shopify_product_id,
                'variant_id' => (string) $purchased->variants->first()->shopify_variant_id,
                'quantity' => 1,
            ]],
            'currency' => 'USD',
            'language' => 'en',
        ]);

        $this->assertSame($checkoutStrategy->uuid, $setting->component->strategy->uuid);
        $this->assertSame(PersonalizationPlacement::ThankYou, $setting->thankYouComponent->placement);
        $this->assertSame('A thank-you offer for you', $setting->thankYouComponent->heading);
        $this->assertTrue($payload['enabled']);
        $this->assertSame($checkoutStrategy->uuid, data_get($payload, 'component.strategy_uuid'));
        $this->assertTrue(data_get($payload, 'thank_you.enabled'));
        $this->assertSame($strategy->uuid, data_get($payload, 'thank_you.component.strategy_uuid'));
        $this->assertSame('thank_you', data_get($payload, 'thank_you.component.placement'));
        $this->assertSame('thank_you', data_get($recommendations, 'context.surface'));
        $recommendedProductId = data_get($recommendations, 'items.0.shopify_product_id');
        $this->assertNotSame((string) $purchased->shopify_product_id, $recommendedProductId);
        $this->assertContains($recommendedProductId, [
            (string) $fallback->shopify_product_id,
            (string) $checkoutProduct->shopify_product_id,
        ]);
        $this->assertSame('thank_you_all_products_fallback', data_get($recommendations, 'items.0.reason_code'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'personalization_thank_you_configuration_saved',
            'store_id' => $store->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_strategy_autosave_keeps_checkout_binding_and_updates_its_version(): void
    {
        [$admin, $organization, $store] = $this->context('Checkout Autosave');
        $product = $this->product($organization, $store, 151);
        $strategy = $this->checkoutStrategy($store, $admin, $product);
        $checkout = app(PersonalizationCheckoutService::class);
        $setting = $checkout->save($store, $admin, [
            'strategy_uuid' => $strategy->uuid,
            'trust_items' => $checkout->defaultTrustItems(),
        ]);
        $componentId = $setting->component_id;

        $workflow = app(PersonalizationStrategyWorkflowService::class);
        $editor = $workflow->editor($store, $strategy->fresh(), $admin);
        $draft = $editor['draft'];
        $draft['configuration']['placements'] = [];
        $draft['configuration']['discount'] = [
            'enabled' => true,
            'reference' => 'gid://shopify/DiscountCodeNode/151',
            'title' => '九折优惠',
            'summary' => '10% off',
            'code' => 'DECO10',
            'status' => 'active',
            'percentage' => 10,
            'validated_at' => now()->toIso8601String(),
        ];
        $saved = $workflow->autosave($store, $strategy->fresh(), $admin, [
            'idempotency_key' => (string) Str::uuid(),
            'lock_version' => $draft['lock_version'],
            'draft' => $draft,
        ]);

        $component = $setting->fresh()->component;
        $this->assertNotNull($component);
        $this->assertSame($componentId, $component->id);
        $this->assertSame('active', $component->status->value);
        $this->assertSame(
            $saved['draft']['uuid'],
            $component->strategyVersion()->value('uuid'),
        );
        $this->assertTrue($checkout->storefront($store)['enabled']);
    }

    public function test_thank_you_configuration_route_is_store_scoped_and_validated(): void
    {
        [$admin, $organization, $store] = $this->context('Thank You Route');
        $product = $this->product($organization, $store, 451);
        $strategy = $this->checkoutStrategy($store, $admin, $product);

        $this->actingAs($admin)
            ->put(route('personalization.thank-you.update', [$organization, $store]), [
                'strategy_uuid' => $strategy->uuid,
                'heading' => 'Recommended after purchase',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('personalization_recommendation_components', [
            'store_id' => $store->id,
            'strategy_id' => $strategy->id,
            'placement' => PersonalizationPlacement::ThankYou->value,
            'heading' => 'Recommended after purchase',
        ]);

        $this->actingAs($admin)
            ->from(route('personalization.index', [$organization, $store]))
            ->put(route('personalization.thank-you.update', [$organization, $store]), [
                'strategy_uuid' => $strategy->uuid,
                'heading' => str_repeat('a', 121),
            ])
            ->assertSessionHasErrors('heading');
    }

    public function test_checkout_binds_and_executes_a_custom_rule_strategy(): void
    {
        [$admin, $organization, $store] = $this->context('Checkout Custom');
        $cartProduct = $this->product($organization, $store, 171);
        $recommendation = $this->product($organization, $store, 172);
        $workflow = app(PersonalizationStrategyWorkflowService::class);
        $created = $workflow->createDraft($store, $admin, (string) Str::uuid());
        $strategy = PersonalizationRecommendationStrategy::query()->sole();
        $draft = $created['draft'];
        $draft['name'] = 'Checkout custom rule';
        $draft['configuration']['recommendation_rule'] = [
            'mode' => 'custom',
            'preset' => 'manual',
            'custom' => [
                'rules' => [[
                    'id' => (string) Str::uuid(),
                    'name' => 'Cart product rule',
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
                            'shopify_product_id' => (string) $recommendation->shopify_product_id,
                            'minimum_quantity' => 2,
                        ]],
                        'filters' => [],
                    ],
                ]],
                'fallback' => ['enabled' => false, 'action' => ['type' => 'manual', 'products' => [], 'filters' => []]],
            ],
        ];
        $saved = $workflow->autosave($store, $strategy, $admin, [
            'idempotency_key' => (string) Str::uuid(),
            'lock_version' => $draft['lock_version'],
            'draft' => $draft,
        ]);
        $checkout = app(PersonalizationCheckoutService::class);

        $setting = $checkout->save($store, $admin, [
            'strategy_uuid' => $strategy->uuid,
            'trust_items' => $checkout->defaultTrustItems(),
        ]);
        $result = $checkout->recommendations($store, [
            'cart_product_ids' => [(string) $cartProduct->shopify_product_id],
            'cart_lines' => [[
                'product_id' => (string) $cartProduct->shopify_product_id,
                'variant_id' => (string) ($cartProduct->variants->first()->shopify_variant_id),
                'quantity' => 1,
            ]],
            'market' => 'us',
            'currency' => 'USD',
            'language' => 'en',
        ]);

        $this->assertSame('active', $setting->component->status->value);
        $this->assertSame($saved['draft']['uuid'], $setting->component->strategyVersion()->value('uuid'));
        $this->assertSame([(string) $recommendation->shopify_product_id], collect($result['items'])->pluck('shopify_product_id')->all());
        $this->assertSame(2, data_get($result, 'items.0.minimum_purchase_quantity'));
        $this->assertSame('custom_rule', data_get($result, 'items.0.reason_code'));
        $this->assertSame('checkout', data_get($result, 'context.surface'));
    }

    public function test_checkout_rejects_cross_store_strategy_and_permanent_denied_store(): void
    {
        [$admin, $organization, $store] = $this->context('Checkout Scope A');
        [$otherAdmin, $otherOrganization, $otherStore] = $this->context('Checkout Scope B');
        $otherProduct = $this->product($otherOrganization, $otherStore, 202);
        $otherStrategy = $this->checkoutStrategy($otherStore, $otherAdmin, $otherProduct);
        $service = app(PersonalizationCheckoutService::class);

        try {
            $service->save($store, $admin, [
                'strategy_uuid' => $otherStrategy->uuid,
                'trust_items' => [],
            ]);
            $this->fail('Checkout must reject a strategy from another store.');
        } catch (PersonalizationException $exception) {
            $this->assertSame('CHECKOUT_STRATEGY_NOT_FOUND', $exception->errorCode);
        }

        $store->forceFill(['shopify_domain' => 'macfoxebike.myshopify.com'])->save();
        try {
            $service->storefront($store->fresh()->load('organization'));
            $this->fail('The permanent denylist must block Checkout reads.');
        } catch (PersonalizationException $exception) {
            $this->assertSame('SHOP_WRITE_DENIED', $exception->errorCode);
        }
    }

    public function test_signed_checkout_endpoint_resolves_shop_from_token_and_returns_no_customer_data(): void
    {
        [$admin, $organization, $store] = $this->context('Checkout Endpoint');
        $product = $this->product($organization, $store, 301);
        $strategy = $this->checkoutStrategy($store, $admin, $product);
        app(PersonalizationCheckoutService::class)->save($store, $admin, [
            'strategy_uuid' => $strategy->uuid,
            'trust_items' => app(PersonalizationCheckoutService::class)->defaultTrustItems(),
        ]);

        $response = $this->withToken($this->checkoutToken($store->shopify_domain))
            ->getJson(route('personalization.checkout.configuration'));
        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.component.strategy_uuid', $strategy->uuid)
            ->assertJsonMissingPath('data.collection')
            ->assertJsonMissingPath('data.sequence')
            ->assertJsonMissingPath('data.store_id')
            ->assertJsonMissingPath('data.organization_id')
            ->assertJsonMissingPath('data.customer');

        $recommendations = $this->withToken($this->checkoutToken($store->shopify_domain))
            ->postJson(route('personalization.checkout.recommendations'), [
                'cart_lines' => [],
                'market' => 'us',
                'currency' => 'USD',
                'language' => 'en',
            ]);
        $recommendations->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.context.surface', 'checkout')
            ->assertJsonPath('data.items.0.shopify_product_id', '301')
            ->assertJsonPath('data.items.0.minimum_purchase_quantity', 1)
            ->assertJsonMissingPath('data.customer');
        $this->withToken($this->checkoutToken($store->shopify_domain))
            ->postJson(route('personalization.checkout.recommendations'), [
                'cart_lines' => [[
                    'product_id' => '301',
                    'variant_id' => '3010',
                    'quantity' => 1,
                ]],
            ])->assertOk()->assertJsonCount(0, 'data.items');

        $this->withToken($this->checkoutToken($store->shopify_domain, 'wrong-secret'))
            ->getJson(route('personalization.checkout.configuration'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'INVALID_SHOPIFY_CHECKOUT_TOKEN');
    }

    /** @return array{User, Organization, Store} */
    private function context(string $name): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create([
            'name' => $name,
            'code' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => 'active',
        ]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => Str::lower(Str::random(12)).'.myshopify.com',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'store-admin')->sole();
        $user->roles()->attach($role, [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
        ]);

        return [$user, $organization, $store];
    }

    private function product(Organization $organization, Store $store, int $shopifyId): Product
    {
        $product = Product::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'shopify_product_id' => $shopifyId,
            'title' => 'Checkout product '.$shopifyId,
            'handle' => 'checkout-product-'.$shopifyId,
            'status' => 'active',
            'tags' => ['Checkout'],
            'published_at_shopify' => now(),
            'synced_at' => now(),
        ]);
        $product->variants()->create([
            'shopify_variant_id' => $shopifyId * 10,
            'title' => 'Default',
            'price' => '99.0000',
            'available_for_sale' => true,
            'selected_options' => [],
        ]);

        return $product->load('variants');
    }

    private function checkoutStrategy(Store $store, User $admin, Product $product): PersonalizationRecommendationStrategy
    {
        $service = app(PersonalizationConfigurationService::class);
        $strategy = $service->createStrategy($store, $admin, [
            'name' => 'Checkout manual',
            'algorithm' => 'manual',
        ]);
        $service->replaceProductOverrides($store, $strategy, $admin, [[
            'shopify_product_id' => 'gid://shopify/Product/'.$product->shopify_product_id,
            'type' => 'manual',
        ]]);

        return $strategy->refresh();
    }

    private function checkoutToken(string $shop, string $secret = 'personalization-test-secret'): string
    {
        $now = now()->timestamp;
        $header = $this->base64Url(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $this->base64Url(json_encode([
            'aud' => 'personalization-test-client-id',
            'dest' => $shop,
            // Checkout's documented token contract does not define `iss`.
            // Shopify may still attach one, so it must not override the
            // verified signature, audience, destination, or lifetime checks.
            'iss' => 'https://checkout.shopify.com',
            'nbf' => $now - 1,
            'iat' => $now,
            'exp' => $now + 60,
        ], JSON_THROW_ON_ERROR));
        $signature = $this->base64Url(hash_hmac('sha256', $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
