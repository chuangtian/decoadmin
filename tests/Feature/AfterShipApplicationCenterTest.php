<?php

namespace Tests\Feature;

use App\Models\App;
use App\Models\AppInstallation;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\ShopifyConnection;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Installations')
                ->has('installations.data', 1)
                ->where('installations.data.0.store.id', $store->id));

        $this->actingAs($user)->withSession($session)
            ->get(route('app-configurations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Apps/Configurations')
                ->has('installation.modules', 6)
                ->where('installation.modules.0.handle', 'personalization')
                ->where('installation.modules.5.handle', 'page_builder')
                ->has('credentialProviders', 2));

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

    public function test_installation_and_log_centers_do_not_leak_unassigned_store_data(): void
    {
        [$user, $organization] = $this->userWithRole('organization-admin');
        $allowed = $this->store($organization, 'Macfox US', 'macfox-us.myshopify.com');
        $blocked = $this->store($organization, 'Macfox EU', 'macfox-eu.myshopify.com');
        $allowed->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->installation($allowed, $user);
        $this->installation($blocked, User::factory()->create());
        AuditLog::query()->create(['organization_id' => $organization->id, 'store_id' => $allowed->id, 'action' => 'marketing_module_configured']);
        AuditLog::query()->create(['organization_id' => $organization->id, 'store_id' => $blocked->id, 'action' => 'marketing_module_configured']);

        $this->actingAs($user)->withSession($this->contextSession($organization, $allowed))
            ->get(route('app-installations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('installations.data', 1)
                ->where('installations.data.0.store.id', $allowed->id));

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
