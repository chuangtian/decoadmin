<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreBusinessCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_store_reveal_preserve_and_clear_an_encrypted_credential(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $session = $this->contextSession($organization, $store);
        $token = 'EAA123456DZD';

        $this->actingAs($user)->withSession($session)
            ->put(route('store-settings.credentials.update', ['provider' => 'meta_ads', 'credentialKey' => 'access_token']), ['value' => $token])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotSame($token, DB::table('store_business_credentials')->value('credential_value'));
        $this->assertSame($token, StoreBusinessCredential::query()->value('credential_value'));

        $this->actingAs($user)->withSession($session)
            ->get(route('store-settings.credentials'))
            ->assertOk()
            ->assertDontSee($token)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stores/Settings/Credentials')
                ->where('store.id', $store->id)
                ->where('credentialProviders.0.key', 'meta_ads')
                ->where('credentialProviders.0.configured', true)
                ->where('credentialProviders.0.fields.0.masked_value', 'EAA***DZD')
                ->where('credentialProviders.0.fields.0.current_value', '')
                ->where('canUpdate', true));

        $this->actingAs($user)->withSession($session)
            ->getJson(route('store-settings.credentials.reveal', ['provider' => 'meta_ads', 'credentialKey' => 'access_token']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.value', $token);

        $this->actingAs($user)->withSession($session)
            ->put(route('store-settings.credentials.update', ['provider' => 'meta_ads', 'credentialKey' => 'access_token']), ['value' => ''])
            ->assertRedirect();
        $this->assertSame($token, StoreBusinessCredential::query()->value('credential_value'));
        $this->assertStringNotContainsString($token, DB::table('audit_logs')->get()->toJson());

        $this->actingAs($user)->withSession($session)
            ->delete(route('store-settings.credentials.destroy', ['provider' => 'meta_ads', 'credentialKey' => 'access_token']))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertDatabaseCount('store_business_credentials', 0);
    }

    public function test_viewer_cannot_reveal_or_change_a_business_credential(): void
    {
        [$user, $organization, $store] = $this->context('viewer');
        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'meta_ads',
            'credential_key' => 'access_token',
            'credential_value' => 'EAA-viewer-hidden-token',
        ]);
        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)->withSession($session)->get(route('store-settings.credentials'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('canUpdate', false));
        $parameters = ['provider' => 'meta_ads', 'credentialKey' => 'access_token'];
        $this->actingAs($user)->withSession($session)->getJson(route('store-settings.credentials.reveal', $parameters))->assertForbidden();
        $this->actingAs($user)->withSession($session)->put(route('store-settings.credentials.update', $parameters), ['value' => 'replacement'])->assertForbidden();
        $this->actingAs($user)->withSession($session)->delete(route('store-settings.credentials.destroy', $parameters))->assertForbidden();
    }

    public function test_business_credentials_are_scoped_to_current_store(): void
    {
        [$user, $organization, $firstStore] = $this->context('organization-admin');
        $secondStore = $this->addStore($user, $organization, 'EU Store', 'credentials-eu.myshopify.com');
        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $firstStore->id,
            'provider' => 'meta_ads',
            'credential_key' => 'access_token',
            'credential_value' => 'EAA-first-store-token',
        ]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $secondStore))
            ->get(route('store-settings.credentials'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('store.id', $secondStore->id)
                ->where('credentialProviders.0.key', 'meta_ads')
                ->where('credentialProviders.0.configured', false));
    }

    public function test_catalog_includes_all_supported_providers_and_exposes_non_secret_values_only(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'tiktok_ads',
            'credential_key' => 'app_id',
            'credential_value' => '7625908528032546832',
        ]);
        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'tiktok_ads',
            'credential_key' => 'app_secret',
            'credential_value' => 'never-send-this-secret',
        ]);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('store-settings.credentials'))
            ->assertOk()
            ->assertDontSee('never-send-this-secret')
            ->assertInertia(fn (Assert $page) => $page
                ->has('credentialProviders', 7)
                ->where('credentialProviders.1.key', 'tiktok_ads')
                ->where('credentialProviders.1.fields.1.current_value', '7625908528032546832')
                ->where('credentialProviders.1.fields.2.current_value', '')
                ->where('credentialProviders.6.key', 'google_search_console_ga4'));
    }

    public function test_unknown_provider_or_credential_key_is_rejected(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)->withSession($session)
            ->put(route('store-settings.credentials.update', ['provider' => 'unknown', 'credentialKey' => 'token']), ['value' => 'secret'])
            ->assertNotFound();

        $this->assertDatabaseCount('store_business_credentials', 0);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Deco', 'code' => 'deco-credentials']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->addStore($user, $organization, 'US Store', 'credentials-us.myshopify.com');
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function addStore(User $user, Organization $organization, string $name, string $domain): Store
    {
        $store = $organization->stores()->create([
            'name' => $name,
            'shopify_domain' => $domain,
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'America/New_York',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        return $store;
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return [
            'current_organization_id' => $organization->id,
            'current_store_id' => $store->id,
        ];
    }
}
