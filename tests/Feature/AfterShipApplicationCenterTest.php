<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\StudentDiscountCampaign;
use App\Models\User;
use App\Support\CurrentStore;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AfterShipApplicationCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shopify.app_handle', 'deco-marketing');
    }

    public function test_application_center_entries_are_live_and_show_the_six_modules(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->installation($store, $user);
        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)->withSession($session)
            ->get(route('app-center.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Index')
                ->has('apps.data', 1));

        $this->actingAs($user)->withSession($session)
            ->get(route('app-installations.index'))
            ->assertRedirect(route('app-center.index'));

        $this->actingAs($user)->withSession($session)
            ->get(route('app-configurations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Configurations')
                ->has('applications', 1)
                ->where('applications.0.app.handle', 'deco-marketing')
                ->where('applications.0.category', '营销与店铺运营')
                ->where('applications.0.action_label', '管理营销模块')
                ->has('applications.0.metrics', 3));

        $this->actingAs($user)->withSession($session)
            ->get(route('app-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Apps/Logs'));
    }

    public function test_each_aftership_module_has_a_store_workspace(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->installation($store, $user);

        foreach (array_keys(config('shopify.marketing_modules')) as $module) {
            $this->actingAs($user)
                ->withSession($this->contextSession($organization, $store))
                ->get(route('stores.marketing.modules.show', [$store, 'module' => $module]))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Marketing/Module')
                    ->where('store.id', $store->id)
                    ->where('module.handle', $module)
                    ->where('module.enabled', true));
        }
    }

    public function test_configuration_center_supports_multiple_apps_and_a_safe_generic_fallback(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $marketingInstallation = $this->installation($store, $user);
        $studentApp = App::query()->create([
            'name' => 'Deco Student Discount Test',
            'handle' => 'deco-student-discount-test',
            'distribution' => 'custom',
            'status' => 'active',
            'settings' => ['managed_by' => 'student_discount_config', 'environment' => 'test'],
        ]);
        $genericApp = App::query()->create([
            'name' => 'Future App',
            'handle' => 'future-app',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $uninstalledApp = App::query()->create([
            'name' => 'Removed App',
            'handle' => 'removed-app',
            'distribution' => 'custom',
            'status' => 'active',
        ]);

        foreach ([
            [$studentApp, 'pending'],
            [$genericApp, 'disabled'],
            [$uninstalledApp, 'uninstalled'],
        ] as [$app, $installationStatus]) {
            AppInstallation::query()->create([
                'app_id' => $app->id,
                'store_id' => $store->id,
                'shopify_connection_id' => $marketingInstallation->shopify_connection_id,
                'installed_by' => $user->id,
                'status' => $installationStatus,
                'granted_scopes' => ['read_products'],
                'installed_at' => now(),
                'uninstalled_at' => $installationStatus === 'uninstalled' ? now() : null,
            ]);
        }

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('app-configurations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('applications', 3)
                ->where('applications.0.app.handle', 'deco-marketing')
                ->where('applications.0.category', '营销与店铺运营')
                ->where('applications.1.app.handle', 'deco-student-discount-test')
                ->where('applications.1.installation_status', 'pending')
                ->where('applications.1.category', '优惠与身份审核')
                ->where('applications.1.action_label', '管理学生优惠')
                ->where('applications.2.app.handle', 'future-app')
                ->where('applications.2.installation_status', 'disabled')
                ->where('applications.2.configuration_status', 'unsupported'));
    }

    public function test_current_store_installation_state_and_configurations_stay_consistent_when_switching_same_named_stores(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $installedStore = $this->store($organization, 'macfox-test-app', 'macfox-test-app.myshopify.com');
        $otherStore = $this->store($organization, 'macfox-test-app', 'macfox-test-app-copy.myshopify.com');
        $installedStore->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $otherStore->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $installedMarketing = $this->installation($installedStore, $user);
        $this->installation($otherStore, $user);
        $studentApp = App::query()->create([
            'name' => 'Deco Student Discount Test',
            'handle' => 'deco-student-discount-test',
            'distribution' => 'custom',
            'status' => 'active',
            'settings' => ['managed_by' => 'student_discount_config', 'environment' => 'test'],
        ]);
        $studentInstallation = AppInstallation::query()->create([
            'app_id' => $studentApp->id,
            'store_id' => $installedStore->id,
            'shopify_connection_id' => $installedMarketing->shopify_connection_id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_discounts', 'write_discounts'],
            'installed_at' => now(),
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $installedStore))
            ->get(route('app-center.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentStore.id', $installedStore->id)
                ->where('apps.data', fn (Collection $apps): bool => $apps->contains(fn (array $app): bool => $app['id'] === $studentApp->id
                    && data_get($app, 'current_store_installation.status') === 'active'
                    && data_get($app, 'current_store_installation.is_installed') === true
                    && ! array_key_exists('installations_count', $app)
                    && ! array_key_exists('installation_records_count', $app)
                    && ! array_key_exists('installations', $app)
                    && ! array_key_exists('store', $app))));

        $this->get(route('app-configurations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.id', $installedStore->id)
                ->where('applications', fn (Collection $applications): bool => $applications
                    ->contains(fn (array $application): bool => data_get($application, 'app.id') === $studentApp->id)));

        $this->from(route('app-center.index'))
            ->put(route('context.store.update'), ['store_id' => $otherStore->id])
            ->assertRedirect(route('app-center.index'))
            ->assertSessionHas('current_store_id', $otherStore->id);
        app(CurrentStore::class)->clear();

        $this->get(route('app-center.index', ['store_id' => $installedStore->id]))
            ->assertOk()
            ->assertSessionHas('current_store_id', $otherStore->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentStore.id', $otherStore->id)
                ->where('apps.data', fn (Collection $apps): bool => $apps->contains(fn (array $app): bool => $app['id'] === $studentApp->id
                    && data_get($app, 'current_store_installation.status') === 'not_installed'
                    && data_get($app, 'current_store_installation.is_installed') === false
                    && ! array_key_exists('installations_count', $app)
                    && ! array_key_exists('installation_records_count', $app)
                    && ! array_key_exists('installations', $app)
                    && ! array_key_exists('store', $app))));

        $this->get(route('app-configurations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.id', $otherStore->id)
                ->where('applications', fn (Collection $applications): bool => ! $applications
                    ->contains(fn (array $application): bool => data_get($application, 'app.id') === $studentApp->id)));

        $this->from(route('app-configurations.index'))
            ->put(route('context.store.update'), ['store_id' => $installedStore->id])
            ->assertRedirect(route('app-configurations.index'))
            ->assertSessionHas('current_store_id', $installedStore->id);
        app(CurrentStore::class)->clear();

        $this->get(route('app-configurations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.id', $installedStore->id)
                ->where('applications', fn (Collection $applications): bool => $applications
                    ->contains(fn (array $application): bool => data_get($application, 'app.id') === $studentApp->id)));

        $studentInstallation->update([
            'status' => 'uninstalled',
            'uninstalled_at' => now(),
        ]);

        $this->get(route('app-center.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentStore.id', $installedStore->id)
                ->where('apps.data', fn (Collection $apps): bool => $apps->contains(fn (array $app): bool => $app['id'] === $studentApp->id
                    && data_get($app, 'current_store_installation.status') === 'uninstalled'
                    && data_get($app, 'current_store_installation.is_installed') === false
                    && ! array_key_exists('installations_count', $app)
                    && ! array_key_exists('installation_records_count', $app))));

        $this->get(route('app-configurations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.id', $installedStore->id)
                ->where('applications', fn (Collection $applications): bool => ! $applications
                    ->contains(fn (array $application): bool => data_get($application, 'app.id') === $studentApp->id)));
    }

    public function test_legacy_installation_route_redirects_to_the_application_list_without_forwarding_query_parameters(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $store = $this->store($organization, 'macfox-test-app', 'macfox-test-app.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('app-installations.index', [
                'search' => 'ignored',
                'status' => 'active',
                'store_id' => 999999,
            ]))
            ->assertRedirect(route('app-center.index'));
    }

    public function test_application_logs_include_student_discount_events_for_the_authorized_store(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $allowed = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $blocked = $this->store($organization, 'Macfox EU', 'macfox-eu.myshopify.com');
        $allowed->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $allowed->id,
            'action' => 'student_discount_campaign_updated',
        ]);
        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $blocked->id,
            'action' => 'student_discount_claim_approved',
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $allowed))
            ->get(route('app-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.action', 'student_discount_campaign_updated')
                ->where('logs.data.0.action_label', '学生优惠活动配置已更新')
                ->where('logs.data.0.store.id', $allowed->id));
    }

    public function test_configuration_summary_does_not_expose_student_discount_state_without_permission(): void
    {
        [$user, $organization] = $this->userWithRole('developer');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'test-token',
            'token_type' => 'offline',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'connected',
            'installed_at' => now(),
        ]);
        $studentApp = App::query()->create([
            'name' => 'Deco Student Discount Test',
            'handle' => 'deco-student-discount-test',
            'distribution' => 'custom',
            'status' => 'active',
            'settings' => ['managed_by' => 'student_discount_config'],
        ]);
        AppInstallation::query()->create([
            'app_id' => $studentApp->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_products'],
            'installed_at' => now(),
        ]);
        StudentDiscountCampaign::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'enabled' => true,
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('app-configurations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('applications', 1)
                ->where('applications.0.configuration_status', 'restricted')
                ->where('applications.0.configuration_status_label', '权限不足')
                ->where('applications.0.management_url', null)
                ->where('applications.0.metrics', [
                    ['label' => '配置范围', 'value' => '当前店铺'],
                ]));
    }

    public function test_admin_can_save_store_scoped_module_configuration_with_an_audit_record(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $installation = $this->installation($store, $user);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('stores.marketing.modules.update', [$store, 'module' => 'email']), [
                'consent_mode' => 'double_opt_in',
                'sender_name' => 'Macfox',
                'sender_email' => 'marketing@example.com',
                'reply_to' => 'support@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $installation->refresh();
        $this->assertSame('double_opt_in', data_get($installation->settings, 'module_settings.email.consent_mode'));
        $this->assertSame('marketing@example.com', data_get($installation->settings, 'module_settings.email.sender_email'));
        $this->assertDatabaseHas('audit_logs', [
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'action' => 'marketing_module_configured',
            'subject_id' => $installation->id,
        ]);
        $this->assertSame('email', data_get(
            AuditLog::query()->where('action', 'marketing_module_configured')->firstOrFail()->metadata,
            'module',
        ));
    }

    public function test_viewer_cannot_change_module_configuration(): void
    {
        [$user, $organization] = $this->userWithRole('viewer');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $installation = $this->installation($store, $user);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('stores.marketing.modules.update', [$store, 'module' => 'email']), [
                'consent_mode' => 'single_opt_in',
                'sender_name' => 'Unauthorized',
                'sender_email' => 'blocked@example.com',
                'reply_to' => '',
            ])
            ->assertForbidden();

        $this->assertNull(data_get($installation->fresh()->settings, 'module_settings.email'));
    }

    public function test_email_provider_secret_is_saved_encrypted_for_the_current_store(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $store = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $secret = 'email-provider-secret-value';

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('store-settings.credentials.update', [
                'provider' => 'email_marketing',
                'credentialKey' => 'api_key',
            ]), ['value' => $secret])
            ->assertRedirect()
            ->assertSessionHas('success');

        $stored = DB::table('store_business_credentials')
            ->where('store_id', $store->id)
            ->where('provider', 'email_marketing')
            ->where('credential_key', 'api_key')
            ->value('credential_value');
        $this->assertIsString($stored);
        $this->assertNotSame($secret, $stored);
        $this->assertDatabaseHas('audit_logs', [
            'store_id' => $store->id,
            'action' => 'store_business_credential_updated',
        ]);
    }

    public function test_application_logs_do_not_leak_unassigned_store_data(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $allowed = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $blocked = $this->store($organization, 'Macfox EU', 'macfox-eu.myshopify.com');
        $allowed->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        AuditLog::query()->create(['organization_id' => $organization->id, 'store_id' => $allowed->id, 'action' => 'marketing_module_configured']);
        AuditLog::query()->create(['organization_id' => $organization->id, 'store_id' => $blocked->id, 'action' => 'marketing_module_configured']);

        $this->actingAs($user)->withSession($this->contextSession($organization, $allowed))
            ->get(route('app-logs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('logs.data', 1)
                ->where('logs.data.0.store.id', $allowed->id));
    }

    /** @return array{0: User, 1: Organization} */
    private function userWithRole(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Macfox', 'code' => 'macfox-aftership']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization];
    }

    private function store(Organization $organization, string $name, string $domain): Store
    {
        return $organization->stores()->create(['name' => $name, 'shopify_domain' => $domain, 'status' => 'active']);
    }

    private function installation(Store $store, User $user): AppInstallation
    {
        $app = App::query()->firstOrCreate(['handle' => 'deco-marketing'], [
            'name' => 'Deco Marketing',
            'distribution' => 'custom',
            'status' => 'active',
        ]);
        $connection = ShopifyConnection::query()->create([
            'store_id' => $store->id,
            'shop_domain' => $store->shopify_domain,
            'access_token_encrypted' => 'test-token',
            'token_type' => 'offline',
            'scopes' => ['read_products'],
            'api_version' => '2026-07',
            'status' => 'connected',
            'installed_at' => now(),
        ]);

        return AppInstallation::query()->create([
            'app_id' => $app->id,
            'store_id' => $store->id,
            'shopify_connection_id' => $connection->id,
            'installed_by' => $user->id,
            'status' => 'active',
            'granted_scopes' => ['read_products'],
            'access_token_encrypted' => 'aftership-test-token',
            'token_type' => 'offline',
            'settings' => ['modules' => array_fill_keys(array_keys(config('shopify.marketing_modules')), true)],
            'installed_at' => now(),
        ]);
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
