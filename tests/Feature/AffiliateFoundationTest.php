<?php

namespace Tests\Feature;

use App\Domain\ReferralAffiliate\Models\AffiliateClick;
use App\Domain\ReferralAffiliate\Models\AffiliateConversion;
use App\Domain\ReferralAffiliate\Models\AffiliateLedgerEntry;
use App\Domain\ReferralAffiliate\Models\AffiliateNotificationIntent;
use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliatePromoter;
use App\Domain\ReferralAffiliate\Services\AffiliateAccountingService;
use App\Domain\ReferralAffiliate\Services\AffiliateAppTokenService;
use App\Domain\ReferralAffiliate\Services\AffiliateAttributionEngine;
use App\Domain\ReferralAffiliate\Services\AffiliateCatalogService;
use App\Domain\ReferralAffiliate\Services\AffiliateCouponSyncService;
use App\Domain\ReferralAffiliate\Services\AffiliateCustomerEligibilityService;
use App\Domain\ReferralAffiliate\Services\AffiliateInvitationOrderReader;
use App\Domain\ReferralAffiliate\Services\AffiliateInvitationService;
use App\Domain\ReferralAffiliate\Services\AffiliateLedgerService;
use App\Domain\ReferralAffiliate\Services\AffiliateManagementService;
use App\Domain\ReferralAffiliate\Services\AffiliateManualAttributionService;
use App\Domain\ReferralAffiliate\Services\AffiliateNotificationService;
use App\Domain\ReferralAffiliate\Services\AffiliateOrderReader;
use App\Domain\ReferralAffiliate\Services\AffiliatePayoutService;
use App\Domain\ReferralAffiliate\Services\AffiliatePostPurchaseService;
use App\Domain\ReferralAffiliate\Services\AffiliateReportService;
use App\Domain\ReferralAffiliate\Services\AffiliateRetentionService;
use App\Domain\ReferralAffiliate\Services\AffiliateRiskEngine;
use App\Domain\ReferralAffiliate\Services\AffiliateTrackingStatistics;
use App\Domain\ReferralAffiliate\Services\AffiliateTrackingTokenService;
use App\Domain\ReferralAffiliate\Services\ShopifyAffiliateAppService;
use App\Exceptions\AffiliateException;
use App\Jobs\SendAffiliateNotification;
use App\Jobs\SyncAffiliateCoupon;
use App\Models\App;
use App\Models\AppInstallation;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AffiliateFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        Queue::fake([SyncAffiliateCoupon::class]);
    }

    public function test_shopify_home_is_a_public_shell_restricted_to_the_pilot_store(): void
    {
        config(['referral.active.client_id' => 'test-referral-client']);
        $this->get('/shopify-app/referral?shop=macfox-test-app.myshopify.com')
            ->assertOk()->assertSee('test-referral-client')
            ->assertHeader('Content-Security-Policy', 'frame-ancestors https://admin.shopify.com https://macfox-test-app.myshopify.com;');
        $this->get('/shopify-app/referral?shop=another-shop.myshopify.com')->assertForbidden();
        $this->get('/shopify-app/referral')->assertForbidden();
        $this->get('/shopify-app/referral/manage?shop=macfox-test-app.myshopify.com')->assertRedirect(route('login'));
    }

    public function test_store_admin_can_create_store_scoped_program_and_promoter_without_exposing_email_hash(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $session = $this->contextSession($organization, $store);
        $this->actingAs($actor)->withSession($session)->post(route('affiliate.programs.store', [$organization, $store]), [
            'name' => 'Creator 12%', 'type' => 'influencer', 'attribution_model' => 'coupon_wins',
            'attribution_window_days' => 30, 'hold_days' => 30,
            'commission_type' => 'percentage', 'rate_basis_points' => 1200,
            'coupon_enabled' => true, 'customer_discount_type' => 'percentage',
            'customer_discount_rate_basis_points' => 1000,
        ])->assertRedirect();

        $program = AffiliateProgram::query()->sole();
        $this->assertSame($store->id, $program->store_id);
        $this->assertSame(1200, $program->rules()->sole()->rate_basis_points);
        $this->actingAs($actor)->withSession($session)->post(route('affiliate.promoters.store', [$organization, $store]), [
            'display_name' => 'Alice Creator', 'email' => 'Alice@Example.com',
            'type' => 'influencer', 'program_public_id' => $program->public_id,
        ])->assertRedirect();

        $promoter = AffiliatePromoter::query()->sole();
        $this->assertSame('alice@example.com', $promoter->email_encrypted);
        $this->assertNotSame('alice@example.com', $promoter->getRawOriginal('email_encrypted'));
        $membership = AffiliateProgramMembership::query()->sole();
        $this->assertSame($store->id, $membership->store_id);
        $this->actingAs($actor)->withSession($session)
            ->post(route('affiliate.programs.transition', [$organization, $store, $program->public_id]), ['action' => 'activate'])
            ->assertRedirect();
        $this->assertSame('active', $program->fresh()->status->value);
        $this->actingAs($actor)->withSession($session)
            ->post(route('affiliate.memberships.transition', [$organization, $store, $membership->public_id]), ['action' => 'approve'])
            ->assertRedirect();
        $this->assertSame('approved', $membership->fresh()->status->value);
        $this->assertNotNull($membership->fresh()->approved_at);
        $this->assertDatabaseCount('affiliate_links', 1);
        $this->assertDatabaseHas('affiliate_coupons', ['membership_id' => $membership->id, 'status' => 'sync_pending']);
        $link = $membership->fresh()->link;
        $this->actingAs($actor)->withSession($session)->put(route('affiliate.settings.update', [$organization, $store]), [
            'affiliate_enabled' => true, 'customer_referral_enabled' => false,
        ])->assertRedirect();
        foreach ([['/products'], 'https://example.invalid', '//example.invalid', '/%0d%0aLocation:bad', '/path%5cbad', '/'.str_repeat('a', 1000)] as $invalidPath) {
            $this->get(route('affiliate.tracking.redirect', $link->public_id).'?'.http_build_query(['to' => $invalidPath]))->assertStatus(422);
            $this->assertDatabaseCount('affiliate_clicks', 0);
        }
        $this->get(route('affiliate.tracking.redirect', $link->public_id).'?utm_source[]=invalid')->assertStatus(422);
        $this->assertDatabaseCount('affiliate_clicks', 0);
        $redirect = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8', 'HTTP_USER_AGENT' => 'Affiliate Test'])
            ->get(route('affiliate.tracking.redirect', $link->public_id))
            ->assertRedirect();
        $this->assertStringStartsWith('https://macfox-test-app.myshopify.com/?ref=', (string) $redirect->headers->get('Location'));
        $click = AffiliateClick::query()->sole();
        $this->assertNotSame('203.0.113.8', $click->ip_hash);
        parse_str(parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $tokens = app(AffiliateTrackingTokenService::class);
        $this->assertSame($click->id, $tokens->verify($store, $query['deco_aff'])?->id);
        $this->assertNull($tokens->verify($store, $query['deco_aff'].'tampered'));
        $payload = json_decode(base64_decode(strtr(explode('.', $query['deco_aff'])[0], '-_', '+/')), true);
        $this->assertArrayNotHasKey('store', $payload);
        $this->assertSame($click->occurred_at->copy()->addDays(30)->timestamp, $payload['exp']);
        $this->travel(31)->days();
        $this->assertNull($tokens->verify($store, $query['deco_aff']));
        $this->travelBack();

        $this->assertStringNotContainsString('203.0.113.8', json_encode($click->toArray(), JSON_THROW_ON_ERROR));
        $this->actingAs($actor)->withSession($session)
            ->post(route('affiliate.memberships.transition', [$organization, $store, $membership->public_id]), ['action' => 'suspend'])
            ->assertRedirect();
        $this->assertSame('suspended', $membership->fresh()->status->value);
        $this->assertSame('disabled', $link->fresh()->status);
        $this->assertSame('sync_pending', $membership->fresh()->coupon->status);
        $this->get(route('affiliate.tracking.redirect', $link->public_id))->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['store_id' => $store->id, 'action' => 'affiliate_program_status_changed']);
        $this->assertDatabaseHas('audit_logs', ['store_id' => $store->id, 'action' => 'affiliate_membership_status_changed']);
        $this->actingAs($actor)->withSession($session)->get(route('affiliate.promoters.index', [$organization, $store]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Affiliate/Index')
            ->where('memberships.0.promoter.email', 'alice@example.com')
            ->missing('memberships.0.promoter.email_hash'));
    }

    public function test_program_from_another_store_cannot_be_used_for_membership(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $otherStore = $this->store($organization, $actor, 'Other', 'other-affiliate.myshopify.com');
        $program = AffiliateProgram::query()->create([
            'organization_id' => $organization->id, 'store_id' => $otherStore->id, 'name' => 'Other Program',
            'type' => 'affiliate', 'status' => 'draft', 'attribution_model' => 'coupon_wins',
            'attribution_window_days' => 30, 'hold_days' => 30, 'currency' => 'USD',
        ]);

        $this->actingAs($actor)->withSession($this->contextSession($organization, $store))
            ->post(route('affiliate.promoters.store', [$organization, $store]), [
                'display_name' => 'Cross Store', 'email' => 'cross@example.com',
                'type' => 'affiliate', 'program_public_id' => $program->public_id,
            ])->assertNotFound();
        $this->assertDatabaseCount('affiliate_program_memberships', 0);
        $this->assertDatabaseCount('affiliate_promoters', 0);
    }

    public function test_viewer_can_view_but_cannot_manage_and_settings_are_store_scoped(): void
    {
        [$viewer, $organization, $store] = $this->context('viewer');
        $session = $this->contextSession($organization, $store);
        $this->actingAs($viewer)->withSession($session)->get(route('affiliate.index', [$organization, $store]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Affiliate/Index')->where('settings.affiliate_enabled', false)
            ->where('permissions.manageSettings', false));
        $this->assertDatabaseCount('affiliate_store_settings', 0);
        $this->actingAs($viewer)->withSession($session)->put(route('affiliate.settings.update', [$organization, $store]), [
            'affiliate_enabled' => true, 'customer_referral_enabled' => false,
        ])->assertForbidden();
    }

    public function test_affiliate_navigation_and_admin_routes_require_an_active_referral_installation(): void
    {
        [$viewer, $organization, $store] = $this->context('viewer', false);
        $session = $this->contextSession($organization, $store);

        $this->actingAs($viewer)->withSession($session)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('applicationAvailability.referral', false));
        $this->actingAs($viewer)->withSession($session)
            ->get(route('affiliate.index', [$organization, $store]))
            ->assertForbidden();

        $installation = $this->installReferralApp($store, $viewer);

        $this->actingAs($viewer)->withSession($session)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('applicationAvailability.referral', true));
        $this->actingAs($viewer)->withSession($session)
            ->get(route('affiliate.index', [$organization, $store]))
            ->assertOk();

        $installation->update(['status' => 'uninstalled', 'uninstalled_at' => now()]);

        $this->actingAs($viewer)->withSession($session)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('applicationAvailability.referral', false));
        $this->actingAs($viewer)->withSession($session)
            ->get(route('affiliate.index', [$organization, $store]))
            ->assertForbidden();
    }

    public function test_permission_and_role_seeders_are_idempotent(): void
    {
        [, $organization] = $this->context('store-admin');
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->assertSame(17, Permission::query()->where('group', 'affiliate')->count());
        $this->assertSame(17, Role::query()->whereBelongsTo($organization)->where('slug', 'store-admin')->sole()->permissions()->where('group', 'affiliate')->count());
        $this->assertSame(7, Role::query()->whereBelongsTo($organization)->where('slug', 'viewer')->sole()->permissions()->where('group', 'affiliate')->count());
    }

    public function test_other_store_is_blocked_even_for_a_super_admin_and_direct_service_calls(): void
    {
        [$actor, $organization, $store] = $this->context('super-admin');
        $other = $this->store($organization, $actor, 'Unauthorized fixture', 'unauthorized-fixture.myshopify.com');
        $this->actingAs($actor)->withSession($this->contextSession($organization, $other))
            ->get(route('affiliate.index', [$organization, $other]))->assertForbidden();
        $this->actingAs($actor)->put(route('affiliate.settings.update', [$organization, $other]), [
            'affiliate_enabled' => true, 'customer_referral_enabled' => false,
        ])->assertForbidden();
        try {
            app(AffiliateManagementService::class)
                ->updateSettings($organization, $other, $actor, ['affiliate_enabled' => true, 'customer_referral_enabled' => false]);
            $this->fail('A direct service call bypassed the pilot boundary');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('affiliate_store_settings', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_production_runtime_is_blocked_even_for_the_authorized_test_shop(): void
    {
        [$actor, $organization, $store] = $this->context('super-admin');
        $previous = app()->environment();
        app()->instance('env', 'production');
        try {
            app(AffiliateManagementService::class)
                ->updateSettings($organization, $store, $actor, ['affiliate_enabled' => true, 'customer_referral_enabled' => false]);
            $this->fail('Production was allowed');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        } finally {
            app()->instance('env', $previous);
        }
        $this->assertDatabaseCount('affiliate_store_settings', 0);
    }

    public function test_referral_refresh_uses_only_matching_app_installation_credentials(): void
    {
        [, $organization, $store] = $this->context('store-admin', false);
        config(['referral.environment' => 'test', 'referral.active.client_id' => 'referral-test-client',
            'referral.active.client_secret' => 'referral-test-secret', 'referral.active.handle' => 'deco-referral-test']);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id, 'shop_domain' => $store->shopify_domain,
            'status' => 'connected', 'access_token_encrypted' => 'commerce-fixture-token', 'scopes' => ['read_orders'], 'api_version' => '2026-07',
        ]);
        $registeredApp = App::query()->create([
            'name' => 'Referral fixture', 'handle' => 'deco-referral-test', 'client_id' => 'referral-test-client',
            'distribution' => 'custom', 'status' => 'active',
            'settings' => ['environment' => 'test', 'managed_by' => 'referral_config'],
        ]);
        $installation = AppInstallation::query()->create([
            'app_id' => $registeredApp->id, 'store_id' => $store->id, 'shopify_connection_id' => $connection->id,
            'status' => 'active', 'token_type' => 'offline', 'settings' => ['environment' => 'test'],
            'access_token_encrypted' => 'old-referral-token', 'access_token_expires_at' => now()->subMinute(),
            'refresh_token_encrypted' => 'referral-refresh', 'refresh_token_expires_at' => now()->addMonth(),
            'granted_scopes' => ['read_orders', 'read_customers', 'read_products', 'write_discounts'],
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://macfox-test-app.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'new-referral-token', 'refresh_token' => 'new-referral-refresh',
                'expires_in' => 86400, 'refresh_token_expires_in' => 7776000,
                'scope' => 'read_orders,read_customers,read_products,write_discounts',
            ]),
        ]);
        $service = app(AffiliateAppTokenService::class);
        $this->assertSame('new-referral-token', $service->accessTokenFor($store));
        Http::assertSent(fn ($request) => $request['client_id'] === 'referral-test-client'
            && $request['refresh_token'] === 'referral-refresh');
        $this->assertSame('commerce-fixture-token', $connection->fresh()->access_token_encrypted);
        $this->assertSame('new-referral-refresh', $installation->fresh()->refresh_token_encrypted);
        $installation->update(['settings' => ['environment' => 'production']]);
        $this->expectException(AffiliateException::class);
        $service->accessTokenFor($store);
    }

    public function test_bootstrap_records_only_referral_installation_and_preserves_commerce_token(): void
    {
        [, $organization, $store] = $this->context('store-admin', false);
        config(['referral.environment' => 'test', 'referral.active.client_id' => 'referral-test-client',
            'referral.active.client_secret' => 'referral-test-secret', 'referral.active.handle' => 'deco-referral-test']);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id, 'shop_domain' => $store->shopify_domain, 'status' => 'connected',
            'access_token_encrypted' => 'commerce-token', 'scopes' => ['read_orders'], 'api_version' => '2026-07',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://macfox-test-app.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'referral-token', 'refresh_token' => 'referral-refresh',
                'expires_in' => 86400, 'refresh_token_expires_in' => 7776000,
                'scope' => 'read_orders,read_customers,read_products,write_discounts',
            ]),
            'https://macfox-test-app.myshopify.com/admin/api/*' => Http::response(['data' => [
                'currentAppInstallation' => ['id' => 'gid://shopify/AppInstallation/42', 'accessScopes' => [
                    ['handle' => 'read_orders'], ['handle' => 'read_customers'], ['handle' => 'read_products'], ['handle' => 'write_discounts'],
                ]], 'shop' => ['myshopifyDomain' => 'macfox-test-app.myshopify.com'],
            ]]),
        ]);
        $service = app(ShopifyAffiliateAppService::class);
        $result = $service->bootstrap($store, 'mock-verified-id-token');
        $this->assertSame('gid://shopify/AppInstallation/42', $result['app_installation_id']);
        $installation = AppInstallation::query()->sole();
        $this->assertSame('referral-token', $installation->access_token_encrypted);
        $this->assertSame('test', $installation->settings['environment']);
        $this->assertSame('referral_config', $installation->app->settings['managed_by']);
        $this->assertSame('commerce-token', $connection->fresh()->access_token_encrypted);
        $this->postJson(route('affiliate.shopify.bootstrap', ['shop' => 'macfox-test-app.myshopify.com']))->assertUnauthorized();
        Http::assertSentCount(2);
    }

    public function test_coupon_sync_retries_without_duplicate_and_disables_after_suspension(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $management = app(AffiliateManagementService::class);
        $program = $management->createProgram($organization, $store, $actor, [
            'name' => 'Coupon sync test', 'type' => 'affiliate', 'attribution_model' => 'coupon_wins',
            'attribution_window_days' => 30, 'hold_days' => 30, 'commission_type' => 'percentage',
            'rate_basis_points' => 1200, 'coupon_enabled' => true, 'customer_discount_type' => 'percentage',
            'customer_discount_rate_basis_points' => 1000,
        ]);
        $management->transitionProgram($organization, $store, $actor, $program->public_id, 'activate');
        $management->updateSettings($organization, $store, $actor, ['affiliate_enabled' => true, 'customer_referral_enabled' => false]);
        $management->createPromoter($organization, $store, $actor, ['display_name' => 'Sync test', 'email' => 'sync@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
        $member = AffiliateProgramMembership::query()->sole();
        $management->transitionMembership($organization, $store, $actor, $member->public_id, 'approve');
        $coupon = $member->coupon()->sole();
        $this->mock(AffiliateAppTokenService::class)->shouldReceive('accessTokenFor')->andReturn('isolated-referral-token');
        $remote = null;
        $creates = 0;
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$remote, &$creates, $coupon) {
            $this->assertTrue($request->hasHeader('X-Shopify-Access-Token', 'isolated-referral-token'));
            $this->assertStringContainsString('macfox-test-app.myshopify.com/admin/api/2026-07/', $request->url());
            if (str_contains($request['query'], 'query ReferralCoupon')) {
                return Http::response(['data' => ['codeDiscountNodeByCode' => $remote]]);
            }
            if (str_contains($request['query'], 'CreateReferralCoupon')) {
                $creates++;
                $this->assertEquals(0.1, $request['variables']['input']['customerGets']['value']['percentage']);
                $remote = ['id' => 'gid://shopify/DiscountCodeNode/999', 'codeDiscount' => ['title' => 'Deco Referral '.$coupon->public_id, 'status' => 'ACTIVE']];
                $operation = 'discountCodeBasicCreate';
            } elseif (str_contains($request['query'], 'UpdateReferralCoupon')) {
                $operation = 'discountCodeBasicUpdate';
            } else {
                $operation = 'discountCodeDeactivate';
                $remote['codeDiscount']['status'] = 'EXPIRED';
            }

            return Http::response(['data' => [$operation => ['codeDiscountNode' => ['id' => $remote['id']], 'userErrors' => []]]]);
        });
        $sync = app(AffiliateCouponSyncService::class);
        $this->assertSame('active', $sync->sync($organization->id, $store->id, $coupon->id)->status);
        // Simulate a lost DB acknowledgement after Shopify created the code.
        $coupon->forceFill(['shopify_discount_id' => null])->save();
        $this->assertSame('active', $sync->sync($organization->id, $store->id, $coupon->id)->status);
        $this->assertSame(1, $creates);
        $management->transitionMembership($organization, $store, $actor, $member->public_id, 'suspend');
        $this->assertSame('disabled', $sync->sync($organization->id, $store->id, $coupon->id)->status);
        $this->assertSame('EXPIRED', $remote['codeDiscount']['status']);
        // A pre-existing unrelated code must never be adopted or modified.
        $remote['codeDiscount']['title'] = 'Another app discount';
        $count = count(Http::recorded());
        try {
            $sync->sync($organization->id, $store->id, $coupon->id);
            $this->fail('Expected collision protection');
        } catch (AffiliateException $exception) {
            $this->assertStringContainsString('不属于', $exception->getMessage());
        }
        $this->assertCount($count + 1, Http::recorded());
        $this->assertNotNull($coupon->fresh()->last_error);
    }

    public function test_variant_rules_use_parent_store_and_edits_audit_the_default_commission(): void
    {
        [$actor, $organization, $store] = $this->context('store-admin');
        $service = app(AffiliateManagementService::class);
        $values = ['name' => 'Rules', 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 30,
            'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false];
        $program = $service->createProgram($organization, $store, $actor, $values);
        $product = Product::query()->create(['organization_id' => $organization->id, 'store_id' => $store->id, 'shopify_product_id' => '111', 'title' => 'Local product', 'handle' => 'local-product', 'status' => 'active', 'synced_at' => now()]);
        $variant = $product->variants()->create(['shopify_variant_id' => '222', 'title' => 'Local variant', 'price' => '10.00']);
        $catalog = app(AffiliateCatalogService::class);
        $this->assertSame([['id' => 'gid://shopify/ProductVariant/222', 'name' => 'Local variant']], $catalog->search($organization, $store, $actor, 'variant', 'Local'));
        $rules = [['scope' => 'variant', 'reference' => 'gid://shopify/ProductVariant/222', 'type' => 'percentage', 'basis_points' => 2000, 'exclude' => false]];
        $service->replaceRules($organization, $store, $actor, $program->public_id, $rules);
        $this->assertSame(2000, $program->rules()->where('scope', 'variant')->sole()->rate_basis_points);
        $other = $this->store($organization, $actor, 'Other', 'other-rules.myshopify.com');
        $product->update(['store_id' => $other->id]);
        $this->assertSame([], $catalog->search($organization, $store, $actor, 'variant', 'Local'));
        try {
            $service->replaceRules($organization, $store, $actor, $program->public_id, $rules);
            $this->fail('Cross-store variant accepted');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $this->assertSame(2000, $program->rules()->where('scope', 'variant')->sole()->rate_basis_points);
        $values['rate_basis_points'] = 1500;
        $service->updateProgram($organization, $store, $actor, $program->public_id, $values);
        $this->assertSame(1500, $program->rules()->where('scope', 'program')->sole()->rate_basis_points);
        $this->assertDatabaseHas('audit_logs', ['action' => 'affiliate_program_updated', 'subject_id' => $program->id]);
    }

    public function test_application_can_be_waitlisted_rejected_with_reason_then_approved(): void
    {
        [$actor,$org,$store] = $this->context('store-admin');
        $program = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => 'Review', 'type' => 'affiliate', 'currency' => 'USD']);
        $svc = app(AffiliateManagementService::class);
        $svc->createPromoter($org, $store, $actor, ['display_name' => 'Applicant', 'email' => 'applicant@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
        $member = AffiliateProgramMembership::query()->sole();
        $svc->transitionMembership($org, $store, $actor, $member->public_id, 'waitlist');
        $this->assertSame('waitlisted', $member->fresh()->status->value);
        $svc->transitionMembership($org, $store, $actor, $member->public_id, 'reject', 'Audience does not match');
        $this->assertSame('rejected', $member->fresh()->status->value);
        $this->assertSame('Audience does not match', $member->fresh()->rejection_reason);
        $this->assertDatabaseCount('affiliate_links', 0);
        $svc->transitionMembership($org, $store, $actor, $member->public_id, 'approve');
        $this->assertSame('approved', $member->fresh()->status->value);
        $this->assertNull($member->fresh()->rejection_reason);
        $this->assertDatabaseCount('affiliate_links', 1);
    }

    public function test_member_override_and_notes_are_store_scoped_and_validated(): void
    {
        [$actor,$org,$store] = $this->context('store-admin');
        $program = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => 'Overrides', 'type' => 'affiliate', 'currency' => 'USD']);
        $svc = app(AffiliateManagementService::class);
        $svc->createPromoter($org, $store, $actor, ['display_name' => 'Test', 'email' => 'override@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
        $member = AffiliateProgramMembership::query()->sole();
        $url = route('affiliate.memberships.update', [$org, $store, $member->public_id]);
        $values = ['tier_key' => 'gold', 'labels' => ['partner', 'partner'], 'admin_notes' => 'Reviewed application',
            'commission_override' => ['commission_type' => 'percentage', 'rate_basis_points' => 2500]];
        $this->actingAs($actor)->withSession($this->contextSession($org, $store))->put($url, $values)->assertRedirect();
        $this->assertSame(['partner'], $member->fresh()->labels);
        $this->assertSame(2500, $member->fresh()->commission_override['rate_basis_points']);
        $values['commission_override']['rate_basis_points'] = 10001;
        $this->put($url, $values)->assertSessionHasErrors('commission_override.rate_basis_points');
        $this->assertSame(2500, $member->fresh()->commission_override['rate_basis_points']);
    }

    public function test_material_upload_stays_private_and_rejects_executable_content(): void
    {
        [$actor,$org,$store] = $this->context('store-admin');
        Storage::fake('local');
        $this->actingAs($actor)->withSession($this->contextSession($org, $store));
        $url = route('affiliate.materials.upload', [$org, $store]);
        $this->post($url, ['title' => 'Campaign copy', 'file' => UploadedFile::fake()->createWithContent('copy.txt', 'Share our new campaign')])->assertRedirect();
        $row = DB::table('affiliate_assets')->sole();
        $this->assertSame($store->id, $row->store_id);
        Storage::disk('local')->assertExists($row->path);
        $this->post($url, ['title' => 'Bad file', 'file' => UploadedFile::fake()->createWithContent('bad.php', '<?php echo 1;')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('affiliate_assets', 1);
        $this->delete(route('affiliate.materials.remove', [$org, $store, $row->public_id]))->assertRedirect();
        Storage::disk('local')->assertMissing($row->path);
    }

    public function test_csv_import_is_atomic_and_duplicate_emails_do_not_overwrite_identity(): void
    {
        [$actor,$org,$store] = $this->context('store-admin');
        $program = AffiliateProgram::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'name' => 'CSV', 'type' => 'affiliate', 'currency' => 'USD']);
        $url = route('affiliate.promoters.import', [$org, $store]);
        $this->actingAs($actor)->withSession($this->contextSession($org, $store));
        $this->post($url, ['program' => $program->public_id, 'file' => UploadedFile::fake()->createWithContent('invalid.csv', "name,email\nGood,good@example.invalid\nBad,invalid\n")])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('affiliate_promoters', 0);
        $this->post($url, ['program' => $program->public_id, 'file' => UploadedFile::fake()->createWithContent('valid.csv', "name,email\nGood,good@example.invalid\n")])->assertRedirect();
        $this->post($url, ['program' => $program->public_id, 'file' => UploadedFile::fake()->createWithContent('again.csv', "name,email\nChanged,good@example.invalid\n")])->assertRedirect();
        $this->assertDatabaseCount('affiliate_promoters', 1);
        $this->assertSame('Good', AffiliatePromoter::query()->sole()->display_name);
        $this->assertDatabaseCount('affiliate_program_memberships', 1);
    }

    public function test_manual_attribution_accounts_for_existing_refunds_and_is_idempotent(): void
    {
        [$actor,$org,$store] = $this->context('store-admin');
        $this->travelTo(now()->startOfSecond());
        $svc = app(AffiliateManagementService::class);
        $program = $svc->createProgram($org, $store, $actor, ['name' => 'Manual', 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 30, 'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false]);
        $svc->createPromoter($org, $store, $actor, ['display_name' => 'Manual test', 'email' => 'manual@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
        $member = AffiliateProgramMembership::query()->sole();
        $svc->transitionMembership($org, $store, $actor, $member->public_id, 'approve');
        $this->travel(5)->seconds();
        $order = ['id' => 'gid://shopify/Order/99', 'name' => '#MANUAL', 'ordered_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'paid' => true, 'is_test' => true, 'cancelled' => false, 'currency' => 'USD', 'discount_codes' => [],
            'lines' => [['id' => 'gid://shopify/LineItem/99', 'quantity' => 2, 'base_minor' => 10000]],
            'refunds' => [['id' => 'gid://shopify/Refund/99', 'created_at' => now()->toIso8601String(), 'lines' => [['line_id' => 'gid://shopify/LineItem/99', 'quantity' => 1, 'base_minor' => 5000]]]]];
        $conversion = app(AffiliateAccountingService::class)->reconcile($store, $order);
        $this->mock(AffiliateOrderReader::class)->shouldReceive('read')->twice()->andReturn($order);
        $manual = app(AffiliateManualAttributionService::class);
        $requestId = (string) Str::uuid();
        $manual->assign($org, $store, $actor, $conversion->public_id, $member->public_id, 'Verified referral proof', $requestId);
        $manual->assign($org, $store, $actor, $conversion->public_id, $member->public_id, 'Verified referral proof', $requestId);
        $this->assertSame('manual', $conversion->fresh()->source);
        $this->assertSame(1000, $conversion->fresh()->commission_minor);
        $this->assertSame(500, $conversion->fresh()->reversed_minor);
        $this->assertSame(500, (int) AffiliateLedgerEntry::query()->sum('amount_minor'));
        $this->assertDatabaseCount('affiliate_ledger_entries', 2);
    }

    public function test_advocate_program_requires_reward_rules_and_approval_links_verified_customer(): void
    {
        [$actor,$org,$store] = $this->context('store-admin');
        $svc = app(AffiliateManagementService::class);
        $values = ['name' => 'Customer referral', 'type' => 'advocate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 1, 'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false,
            'reward' => ['type' => 'fixed', 'amount_minor' => 1000, 'valid_days' => 30, 'scope' => 'all', 'resource_ids' => []], 'milestones' => []];
        $program = $svc->createProgram($org, $store, $actor, $values);
        $this->assertSame(1000, data_get($program->settings, 'reward.amount_minor'));
        $svc->createPromoter($org, $store, $actor, ['display_name' => 'Customer', 'email' => 'customer@example.invalid', 'type' => 'advocate', 'program_public_id' => $program->public_id]);
        $member = AffiliateProgramMembership::query()->sole();
        $this->mock(AffiliateCustomerEligibilityService::class)->shouldReceive('purchasedCustomer')->once()->andReturn(['eligible' => true, 'customer_id' => 'gid://shopify/Customer/123']);
        $svc->transitionMembership($org, $store, $actor, $member->public_id, 'approve');
        $this->assertSame('gid://shopify/Customer/123', $member->fresh()->shopify_customer_id);
        $this->actingAs($actor)->withSession($this->contextSession($org, $store))->get(route('affiliate.finance.index', [$org, $store, 'rewards']))->assertOk();
    }

    public function test_invitation_is_hashed_one_time_and_does_not_approve_membership(): void
    {
        [$actor,$org,$store] = $this->context('store-admin');
        $svc = app(AffiliateManagementService::class);
        $svc->updateSettings($org, $store, $actor, ['affiliate_enabled' => true, 'customer_referral_enabled' => false]);
        $program = $svc->createProgram($org, $store, $actor, ['name' => 'Invite test', 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 1, 'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false]);
        $svc->transitionProgram($org, $store, $actor, $program->public_id, 'activate');
        $svc->createPromoter($org, $store, $actor, ['display_name' => 'Invited', 'email' => 'invited@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
        $member = AffiliateProgramMembership::query()->sole();
        $response = $this->actingAs($actor)->withSession($this->contextSession($org, $store))->postJson('/organizations/'.$org->id.'/stores/'.$store->id.'/affiliate/memberships/'.$member->public_id.'/invite')->assertOk();
        $token = substr($response->json('url'), -64);
        $this->assertSame(hash('sha256', $token), DB::table('affiliate_invitations')->sole()->token_hash);
        $invites = app(AffiliateInvitationService::class);
        $invites->accept($store, $token, ['terms' => true, 'notes' => 'Synthetic invitation acceptance']);
        $this->assertSame('pending', $member->fresh()->status->value);
        $this->assertNotNull(DB::table('affiliate_invitations')->sole()->accepted_at);
        $this->assertSame('Synthetic invitation acceptance', data_get($member->fresh()->application_encrypted, 'notes'));
        $this->expectException(HttpException::class);
        $invites->accept($store, $token, ['terms' => true]);
    }

    public function test_program_dates_can_be_cleared_without_changing_historical_eligibility(): void
    {
        [$actor,$org,$store] = $this->context('store-admin');
        $this->travelTo(now()->startOfSecond());
        $svc = app(AffiliateManagementService::class);
        $values = ['name' => 'Scheduled', 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 1, 'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false, 'starts_at' => now()->addDays(1)->toIso8601String(), 'ends_at' => now()->addDays(2)->toIso8601String()];
        $p = $svc->createProgram($org, $store, $actor, $values);
        $before = CarbonImmutable::now();
        $this->travel(1)->minutes();
        $values['starts_at'] = null;
        $values['ends_at'] = null;
        $svc->updateProgram($org, $store, $actor, $p->public_id, $values);
        $this->assertNull($p->fresh()->starts_at);
        $this->assertNull($p->fresh()->ends_at);
        $engine = app(AffiliateAttributionEngine::class);
        $this->assertNotNull($engine->valueAt($p->fresh(), 'starts_at', $before));
        $this->assertNull($engine->valueAt($p->fresh(), 'starts_at', CarbonImmutable::now()));
        $values['starts_at'] = now()->toIso8601String();
        $values['ends_at'] = now()->subHour()->toIso8601String();
        $this->expectException(ValidationException::class);
        $svc->updateProgram($org, $store, $actor, $p->public_id, $values);
    }

    public function test_report_window_and_ranking_use_refund_net_sales(): void
    {
        [$actor,$org,$store] = $this->context('store-admin');
        $svc = app(AffiliateManagementService::class);
        $program = $svc->createProgram($org, $store, $actor, ['name' => 'Reports', 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 1, 'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false]);
        $svc->createPromoter($org, $store, $actor, ['display_name' => 'Report member', 'email' => 'report@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
        $member = AffiliateProgramMembership::query()->sole();
        foreach ([1, 40] as $age) {
            AffiliateConversion::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'shopify_order_id' => 'gid://shopify/Order/'.$age, 'order_name' => '#'.$age, 'reason' => 'report_fixture', 'status' => 'partially_refunded', 'source' => 'coupon', 'currency' => 'USD', 'base_minor' => 10000, 'refunded_base_minor' => 2000, 'commission_minor' => 1000, 'reversed_minor' => 200, 'rule_snapshot' => [], 'order_snapshot' => [], 'attribution_snapshot' => [], 'ordered_at' => now()->subDays($age), 'shopify_updated_at' => now()]);
        }
        $service = app(AffiliateReportService::class);
        $recent = $service->metrics($org, $store, $actor, 7);
        $this->assertSame(1, $recent['orders']);
        $this->assertSame('80.00', $recent['net_sales']);
        $this->assertSame('Report member', $recent['ranking'][0]['name']);
        $this->assertSame('8.00', $recent['ranking'][0]['commission']);
        $this->assertSame(100.0, $recent['refund_rate']);
        $this->assertCount(1, $recent['trend']);
        $this->assertSame(2, $service->metrics($org, $store, $actor, 90)['orders']);
    }

    public function test_retention_preserves_totals_and_financial_records(): void
    {
        [$actor,$org,$store] = $this->context('store-admin');
        $svc = app(AffiliateManagementService::class);
        $program = $svc->createProgram($org, $store, $actor, ['name' => 'Retention', 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 1, 'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false]);
        $svc->createPromoter($org, $store, $actor, ['display_name' => 'Retention', 'email' => 'retention@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
        $member = AffiliateProgramMembership::query()->sole();
        $svc->transitionMembership($org, $store, $actor, $member->public_id, 'approve');
        $member->refresh();
        foreach ([1, 190] as $age) {
            AffiliateClick::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'link_id' => $member->link->id, 'visitor_token' => hash('sha256', (string) $age), 'occurred_at' => now()->subDays($age), 'ip_hash' => str_repeat('a', 64)]);
        }
        app(AffiliateLedgerService::class)->adjust($org, $store, $actor, $member->public_id, 100, 'retention fixture', (string) Str::uuid());
        $mail = app(AffiliateNotificationService::class)->intent($member, 'conversion.created', 'retention:old');
        $mail->update(['created_at' => now()->subDays(31)]);
        DB::table('affiliate_portal_tokens')->insert(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $member->id, 'token_hash' => str_repeat('f', 64), 'expires_at' => now()->subDays(31), 'created_at' => now()->subDays(32), 'updated_at' => now()]);
        $retention = app(AffiliateRetentionService::class);
        $counts = $retention->prune($store);
        $this->assertSame(1, $counts['clicks']);
        $this->assertSame(1, $counts['messages']);
        $this->assertSame(1, $counts['affiliate_portal_tokens']);
        $this->assertSame([], $mail->fresh()->message_encrypted);
        $this->assertDatabaseCount('affiliate_clicks', 1);
        $this->assertDatabaseCount('affiliate_ledger_entries', 1);
        $stats = app(AffiliateTrackingStatistics::class);
        $this->assertSame(2, $stats->count($store));
        $this->assertSame(1, $stats->count($store, from: now()->subDays(7)));
        $this->assertSame(0, $retention->prune($store)['clicks']);
        $this->assertSame(2, $stats->count($store));
    }

    public function test_post_purchase_invitation_is_opt_in_and_deduplicated_with_safe_mail_delivery(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        [$actor,$org,$store] = $this->context('store-admin');
        $svc = app(AffiliateManagementService::class);
        $svc->updateSettings($org, $store, $actor, ['affiliate_enabled' => false, 'customer_referral_enabled' => true]);
        $program = $svc->createProgram($org, $store, $actor, ['name' => 'Post purchase', 'type' => 'advocate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 1, 'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false, 'auto_invite' => true, 'reward' => ['type' => 'fixed', 'amount_minor' => 1000, 'valid_days' => 30, 'scope' => 'all', 'resource_ids' => []]]);
        $svc->transitionProgram($org, $store, $actor, $program->public_id, 'activate');
        $this->travel(2)->seconds();
        $notifications = app(AffiliateNotificationService::class);
        $notifications->save($org, $store, $actor, 'customer.invited', ['subject' => 'Invitation', 'body' => 'Join {program}: {invitation_url}', 'enabled' => true]);
        $this->mock(AffiliateInvitationOrderReader::class)->shouldReceive('eligibleContact')->times(3)->andReturn(['customer_id' => 'gid://shopify/Customer/80', 'email' => 'post-purchase@example.invalid', 'ordered_at' => now()->toIso8601String()]);
        $service = app(AffiliatePostPurchaseService::class);
        $service->prepare($org->id, $store->id, 'gid://shopify/Order/80', $program->id);
        $service->prepare($org->id, $store->id, 'gid://shopify/Order/80', $program->id);
        $this->assertDatabaseCount('affiliate_program_memberships', 1);
        $this->assertDatabaseCount('affiliate_invitations', 1);
        $intent = AffiliateNotificationIntent::query()->where('event_key', 'customer.invited')->sole();
        $this->assertSame('queued', $intent->status);
        $this->assertStringNotContainsString('post-purchase@example.invalid', $intent->getRawOriginal('message_encrypted'));
        Queue::assertPushed(SendAffiliateNotification::class, 1);
        config(['mail.default' => 'array']);
        $notifications->send($intent->id);
        $this->assertSame('sent', $intent->fresh()->status);
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public static function correctionCases(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('correctionCases')]
    public function test_unsettled_attribution_correction_preserves_history_and_routes_later_refunds(bool $initialRefund): void
    {
        Queue::fake();
        [$actor,$org,$store] = $this->context('store-admin');
        $this->travelTo(now()->startOfSecond());
        $svc = app(AffiliateManagementService::class);
        $svc->updateSettings($org, $store, $actor, ['affiliate_enabled' => true, 'customer_referral_enabled' => false]);
        $members = [];
        foreach ([1000, 2000] as $rate) {
            $program = $svc->createProgram($org, $store, $actor, ['name' => 'Correction '.$rate, 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 0, 'commission_type' => 'percentage', 'rate_basis_points' => $rate, 'coupon_enabled' => true, 'customer_discount_type' => 'percentage', 'customer_discount_rate_basis_points' => 1000]);
            $svc->transitionProgram($org, $store, $actor, $program->public_id, 'activate');
            $promoter = $svc->createPromoter($org, $store, $actor, ['display_name' => 'Member '.$rate, 'email' => 'member'.$rate.'@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
            $member = $program->memberships()->where('promoter_id', $promoter->id)->firstOrFail();
            $svc->transitionMembership($org, $store, $actor, $member->public_id, 'approve');
            $member->refresh();
            $member->coupon->update(['status' => 'active', 'last_synced_at' => now()]);
            $members[] = $member;
        }
        [$old,$new] = $members;
        $this->travel(2)->seconds();
        $order = ['id' => 'gid://shopify/Order/901', 'name' => '#CORRECTION', 'ordered_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'paid' => true, 'cancelled' => false, 'is_test' => true, 'currency' => 'USD', 'customer_id' => 'gid://shopify/Customer/901', 'discount_codes' => [$old->coupon->code], 'lines' => [['id' => 'gid://shopify/LineItem/901', 'quantity' => 2, 'base_minor' => 20000]], 'refunds' => []];
        $accounting = app(AffiliateAccountingService::class);
        if ($initialRefund) {
            $order['refunds'] = [['id' => 'gid://shopify/Refund/900', 'created_at' => now()->toIso8601String(), 'lines' => [['line_id' => 'gid://shopify/LineItem/901', 'quantity' => 1, 'base_minor' => 10000]]]];
        }$c = $accounting->reconcile($store, $order);
        $original = AffiliateLedgerEntry::query()->where('type', 'commission_accrual')->sole();
        $reader = $this->mock(AffiliateOrderReader::class);
        $reader->shouldReceive('read')->twice()->andReturn($order);
        $manual = app(AffiliateManualAttributionService::class);
        $request = (string) Str::uuid();
        $manual->assign($org, $store, $actor, $c->public_id, $new->public_id, 'Correct verified ownership', $request);
        $manual->assign($org, $store, $actor, $c->public_id, $new->public_id, 'Correct verified ownership', $request);
        $this->assertSame(2000, $original->fresh()->amount_minor);
        $this->assertSame('superseded', $original->fresh()->status);
        $this->assertSame(0, (int) AffiliateLedgerEntry::query()->where('membership_id', $old->id)->sum('amount_minor'));
        $this->assertSame(4000, $c->fresh()->commission_minor);
        $this->assertDatabaseCount('affiliate_attribution_changes', 1);
        $order['refunds'][] = ['id' => 'gid://shopify/Refund/901', 'created_at' => now()->toIso8601String(), 'lines' => [['line_id' => 'gid://shopify/LineItem/901', 'quantity' => 1, 'base_minor' => 10000]]];
        $accounting->reconcile($store, $order);
        $this->assertSame($initialRefund ? 0 : 2000, (int) AffiliateLedgerEntry::query()->where('membership_id', $new->id)->sum('amount_minor'));
        $this->assertSame($initialRefund ? 4000 : 2000, $c->fresh()->reversed_minor);
        $flag = $c->risks()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'rule' => 'order_velocity', 'status' => 'open', 'evidence' => []]);
        app(AffiliateLedgerService::class)->review($org, $store, $actor, $flag->public_id, 'approved', 'Verified synthetic source');
        $this->assertSame($initialRefund ? 'refunded' : 'partially_refunded', $c->fresh()->status);
        $this->actingAs($actor)->withSession($this->contextSession($org, $store))->get(route('affiliate.finance.index', [$org, $store, 'conversions']))->assertOk();
        $ledger = app(AffiliateLedgerService::class);
        $ledger->adjust($org, $store, $actor, $old->public_id, 500, 'Independent new earning', (string) Str::uuid());
        $ledger->release($store);
        $payout = app(AffiliatePayoutService::class);
        $batch = $payout->create($org, $store, $actor, 'USD', 100, CarbonImmutable::now());
        $this->assertSame(500, $batch->items()->where('membership_id', $old->id)->sole()->amount_minor);
        $payout->transition($org, $store, $actor, $batch->public_id, 'cancelled');
        AffiliateLedgerEntry::query()->where('membership_id', $new->id)->update(['status' => 'settled']);
        $reader->shouldReceive('read')->once()->andReturn($order);
        $this->expectException(HttpException::class);
        $manual->assign($org, $store, $actor, $c->public_id, $old->public_id, 'Cannot rewrite a settled payout', (string) Str::uuid());
    }

    public function test_velocity_and_same_source_risks_exclude_duplicate_order_and_hide_hashes(): void
    {
        Queue::fake();
        [$actor,$org,$store] = $this->context('store-admin');
        $this->travelTo(now()->startOfSecond());
        $svc = app(AffiliateManagementService::class);
        $p = $svc->createProgram($org, $store, $actor, ['name' => 'Risk checks', 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 1, 'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false]);
        $svc->createPromoter($org, $store, $actor, ['display_name' => 'Risk member', 'email' => 'risk@example.invalid', 'type' => 'affiliate', 'program_public_id' => $p->public_id]);
        $m = AffiliateProgramMembership::query()->sole();
        $svc->transitionMembership($org, $store, $actor, $m->public_id, 'approve');
        $m->refresh();
        for ($i = 0; $i < 10; $i++) {
            $click = AffiliateClick::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $m->id, 'link_id' => $m->link->id, 'visitor_token' => hash('sha256', 'risk'.$i), 'occurred_at' => now()->subSeconds(20), 'ip_hash' => str_repeat('a', 64), 'ua_hash' => str_repeat('b', 64)]);
        }
        AffiliateConversion::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'membership_id' => $m->id, 'shopify_order_id' => 'gid://shopify/Order/500', 'order_name' => '#RISK', 'reason' => 'risk_fixture', 'status' => 'pending', 'source' => 'signed_cart_token', 'currency' => 'USD', 'base_minor' => 10000, 'commission_minor' => 1000, 'rule_snapshot' => [], 'order_snapshot' => [], 'attribution_snapshot' => ['source_click_id' => $click->id], 'ordered_at' => now()->subSeconds(10), 'shopify_updated_at' => now()]);
        config(['referral.risk.order_velocity_limit' => 2, 'referral.risk.same_source_order_limit' => 2, 'referral.risk.click_burst_limit' => 10]);
        $engine = app(AffiliateRiskEngine::class);
        $order = ['id' => 'gid://shopify/Order/501', 'ordered_at' => now()->toIso8601String()];
        $flags = $engine->evaluate($store, $m, $order, $click);
        $this->assertSame(['order_velocity', 'same_source_orders', 'click_burst'], array_keys($flags));
        $this->assertStringNotContainsString(str_repeat('a', 64), json_encode($flags));
        $order['id'] = 'gid://shopify/Order/500';
        $flags = $engine->evaluate($store, $m, $order, $click);
        $this->assertArrayNotHasKey('order_velocity', $flags);
        $this->assertArrayNotHasKey('same_source_orders', $flags);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $role, bool $withInstallation = true): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create(['name' => 'Affiliate Organization', 'code' => 'affiliate-org']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->store($organization, $user, 'Affiliate Store', 'macfox-test-app.myshopify.com');
        $this->seed(RoleSeeder::class);
        $systemRole = Role::query()->whereBelongsTo($organization)->where('slug', $role)->firstOrFail();
        $user->roles()->attach($systemRole, ['organization_id' => $organization->id, 'store_id' => null]);
        if ($withInstallation) {
            $this->installReferralApp($store, $user);
        }

        return [$user, $organization, $store];
    }

    private function installReferralApp(Store $store, User $user): AppInstallation
    {
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'status' => 'connected',
            'access_token_encrypted' => 'commerce-fixture-token',
            'scopes' => ['read_orders'],
            'api_version' => '2026-07',
        ]);
        $app = App::query()->create([
            'name' => 'Deco 推荐与联盟 test',
            'handle' => (string) config('referral.active.handle'),
            'distribution' => 'custom',
            'status' => 'active',
        ]);

        return AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => [],
            'installed_at' => now(),
        ]);
    }

    private function store(Organization $organization, User $user, string $name, string $domain): Store
    {
        $store = $organization->stores()->create(['name' => $name, 'shopify_domain' => $domain, 'status' => 'active', 'currency' => 'USD']);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return $store;
    }

    /** @return array<string, int> */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
