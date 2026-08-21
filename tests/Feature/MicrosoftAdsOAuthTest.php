<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\User;
use App\Services\MicrosoftAds\MicrosoftAdsOAuthService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MicrosoftAdsOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bing_ads.redirect_uri', 'http://localhost:8000/store-settings/credentials/bing-ads/oauth/callback');
        config()->set('services.bing_ads.state_ttl_minutes', 10);
    }

    public function test_admin_can_start_microsoft_ads_oauth_for_the_current_store(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $this->credential($store, $user, 'client_id', 'microsoft-client-id');
        $this->credential($store, $user, 'client_secret', 'microsoft-client-secret');

        $response = $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('bing-ads.oauth.redirect'));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('microsoft-client-id', $query['client_id']);
        $this->assertSame('openid offline_access https://ads.microsoft.com/msads.manage', $query['scope']);
        $this->assertSame('query', $query['response_mode']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame(config('services.bing_ads.redirect_uri'), $query['redirect_uri']);
        $response->assertSessionHas(MicrosoftAdsOAuthService::SESSION_KEY, fn (array $state): bool => hash_equals($state['state_hash'], hash('sha256', $query['state']))
            && $state['organization_id'] === $organization->id
            && $state['store_id'] === $store->id
            && $state['user_id'] === $user->id
        );
    }

    public function test_successful_callback_stores_refresh_token_and_marks_provider_connected(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response([
                'access_token' => 'microsoft-access-token',
                'expires_in' => 3600,
                'refresh_token' => 'microsoft-refresh-token',
                'scope' => 'https://ads.microsoft.com/msads.manage',
                'token_type' => 'Bearer',
            ]),
        ]);
        [$user, $organization, $store] = $this->context('organization-admin');
        $this->credential($store, $user, 'client_id', 'microsoft-client-id');
        $this->credential($store, $user, 'client_secret', 'microsoft-client-secret');
        $authorization = app(MicrosoftAdsOAuthService::class)->begin($store, $user);
        $session = [
            ...$this->contextSession($organization, $store),
            MicrosoftAdsOAuthService::SESSION_KEY => $authorization['session'],
        ];

        $this->actingAs($user)->withSession($session)
            ->get(route('bing-ads.oauth.callback', [
                'state' => $authorization['state'],
                'code' => 'microsoft-authorization-code',
            ]))
            ->assertRedirect(route('store-settings.credentials'))
            ->assertSessionHas('success', 'Microsoft Ads 已连接。')
            ->assertSessionMissing(MicrosoftAdsOAuthService::SESSION_KEY);

        $refreshToken = StoreBusinessCredential::query()
            ->where('store_id', $store->id)
            ->where('provider', 'bing_ads')
            ->where('credential_key', 'refresh_token')
            ->sole();
        $this->assertSame('microsoft-refresh-token', $refreshToken->credential_value);
        $this->assertStringNotContainsString('microsoft-refresh-token', (string) DB::table('store_business_credentials')->where('id', $refreshToken->id)->value('credential_value'));
        $this->assertStringNotContainsString('microsoft-refresh-token', DB::table('audit_logs')->get()->toJson());

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('store-settings.credentials'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('credentialProviders.3.key', 'bing_ads')
                ->where('credentialProviders.3.oauth_ready', true)
                ->where('credentialProviders.3.oauth_connected', true)
                ->where('credentialProviders.3.oauth_connect_url', route('bing-ads.oauth.redirect')));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://login.microsoftonline.com/common/oauth2/v2.0/token'
            && $request['client_id'] === 'microsoft-client-id'
            && $request['client_secret'] === 'microsoft-client-secret'
            && $request['code'] === 'microsoft-authorization-code'
            && $request['grant_type'] === 'authorization_code');
    }

    public function test_viewer_cannot_start_microsoft_ads_oauth(): void
    {
        [$user, $organization, $store] = $this->context('viewer');
        $this->credential($store, $user, 'client_id', 'microsoft-client-id');
        $this->credential($store, $user, 'client_secret', 'microsoft-client-secret');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('bing-ads.oauth.redirect'))
            ->assertForbidden();
    }

    public function test_callback_rejects_state_from_another_store(): void
    {
        [$user, $organization, $firstStore] = $this->context('organization-admin');
        $secondStore = $this->addStore($user, $organization, 'EU Store', 'microsoft-ads-eu.myshopify.com');
        $this->credential($firstStore, $user, 'client_id', 'microsoft-client-id');
        $this->credential($firstStore, $user, 'client_secret', 'microsoft-client-secret');
        $authorization = app(MicrosoftAdsOAuthService::class)->begin($firstStore, $user);

        $this->actingAs($user)->withSession([
            ...$this->contextSession($organization, $secondStore),
            MicrosoftAdsOAuthService::SESSION_KEY => $authorization['session'],
        ])->get(route('bing-ads.oauth.callback', [
            'state' => $authorization['state'],
            'code' => 'microsoft-authorization-code',
        ]))->assertRedirect(route('store-settings.credentials'))
            ->assertSessionHas('error', 'Microsoft Ads 授权状态已失效，请重新连接。');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('store_business_credentials', ['credential_key' => 'refresh_token']);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Deco', 'code' => 'deco-microsoft-ads']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->addStore($user, $organization, 'US Store', 'microsoft-ads-us.myshopify.com');
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

    private function credential(Store $store, User $user, string $key, string $value): void
    {
        StoreBusinessCredential::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'provider' => 'bing_ads',
            'credential_key' => $key,
            'credential_value' => $value,
            'updated_by' => $user->id,
        ]);
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
