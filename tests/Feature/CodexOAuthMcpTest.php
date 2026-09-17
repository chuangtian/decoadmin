<?php

namespace Tests\Feature;

use App\Models\CodexApiToken;
use App\Models\CodexOAuthRefreshToken;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\AnalyticsCacheVersionService;
use App\Services\Codex\CodexOAuthService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CodexOAuthMcpTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_and_protected_resource_metadata_are_discoverable(): void
    {
        $resource = app(CodexOAuthService::class)->resource();

        $this->getJson('/.well-known/oauth-protected-resource')
            ->assertOk()
            ->assertJsonPath('resource', $resource)
            ->assertJsonPath('authorization_servers.0', rtrim((string) config('app.url'), '/'))
            ->assertJsonPath('scopes_supported.0', 'stores:read');

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('authorization_endpoint', rtrim((string) config('app.url'), '/').'/oauth/authorize')
            ->assertJsonPath('registration_endpoint', rtrim((string) config('app.url'), '/').'/oauth/register')
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256')
            ->assertJsonPath('token_endpoint_auth_methods_supported.0', 'none')
            ->assertJsonPath('authorization_response_iss_parameter_supported', true);
    }

    public function test_dynamic_registration_only_accepts_chatgpt_https_callbacks_and_is_idempotent(): void
    {
        $payload = [
            'client_name' => 'DecoAdmin test connector',
            'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
        $first = $this->postJson('/oauth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('token_endpoint_auth_method', 'none');
        $second = $this->postJson('/oauth/register', $payload)
            ->assertOk()
            ->assertJsonPath('client_id', $first->json('client_id'));
        $this->assertDatabaseCount('codex_oauth_clients', 1);

        $this->postJson('/oauth/register', [
            ...$payload,
            'client_name' => 'Untrusted connector',
            'redirect_uris' => ['https://attacker.example/callback'],
        ])->assertBadRequest()
            ->assertJsonPath('error', 'invalid_redirect_uri');
    }

    public function test_authorization_code_pkce_flow_connects_current_user_and_remote_mcp(): void
    {
        [$user, $organization, $store] = $this->context();
        $clientId = $this->registerClient();
        $verifier = str_repeat('v', 64);
        $request = $this->authorizationRequest($clientId, $verifier, implode(' ', CodexApiToken::SUPPORTED_ABILITIES));

        $this->actingAs($user)->get('/oauth/authorize?'.http_build_query($request))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CodexOAuth/Authorize')
                ->where('client.id', $clientId)
                ->has('abilities', count(CodexApiToken::SUPPORTED_ABILITIES))
                ->where('organizations.0.id', $organization->id));

        $redirect = $this->actingAs($user)->post('/oauth/authorize', [
            ...$request,
            'decision' => 'approve',
            'organization_id' => $organization->id,
        ])->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $parameters);
        $this->assertSame('oauth-state-001', $parameters['state'] ?? null);
        $this->assertSame(rtrim((string) config('app.url'), '/'), $parameters['iss'] ?? null);
        $this->assertNotEmpty($parameters['code'] ?? null);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $parameters['code'],
            'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
            'code_verifier' => $verifier,
            'resource' => app(CodexOAuthService::class)->resource(),
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in', 3600);
        $accessToken = $tokens->json('access_token');
        $this->assertStringStartsWith('dca_', $accessToken);
        $this->assertStringStartsWith('dcrf_', $tokens->json('refresh_token'));

        $this->withToken($accessToken)->postJson('/mcp/decoadmin', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18'],
        ])->assertOk()
            ->assertHeader('MCP-Protocol-Version', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'decoadmin');

        $this->withToken($accessToken)->postJson('/mcp/decoadmin', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => [],
        ])->assertOk()->assertJsonCount(12, 'result.tools');

        $this->withToken($accessToken)->postJson('/mcp/decoadmin', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'decoadmin_list_stores', 'arguments' => []],
        ])->assertOk()
            ->assertJsonPath('result.isError', null)
            ->assertJsonPath('result.structuredContent.data.items.0.id', $store->id)
            ->assertSee('Macfox Bike', false);

        $this->assertDatabaseHas('codex_api_tokens', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'source' => 'oauth',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'codex_oauth_authorized', 'user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'codex_oauth_access_issued', 'user_id' => $user->id]);
    }

    public function test_pkce_code_cannot_be_replayed_and_wrong_verifier_is_rejected(): void
    {
        [$user, $organization] = $this->context();
        $clientId = $this->registerClient();
        $verifier = str_repeat('p', 64);
        $request = $this->authorizationRequest($clientId, $verifier, 'stores:read');
        $redirect = $this->actingAs($user)->post('/oauth/authorize', [
            ...$request, 'decision' => 'approve', 'organization_id' => $organization->id,
        ])->headers->get('Location');
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $parameters);
        $exchange = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $parameters['code'],
            'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
            'resource' => app(CodexOAuthService::class)->resource(),
        ];

        $this->post('/oauth/token', [...$exchange, 'code_verifier' => str_repeat('x', 64)])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $this->post('/oauth/token', [...$exchange, 'code_verifier' => $verifier])->assertOk();
        $this->post('/oauth/token', [...$exchange, 'code_verifier' => $verifier])
            ->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
    }

    public function test_refresh_rotates_access_token_and_revocation_disconnects_it(): void
    {
        [$user, $organization] = $this->context();
        [$clientId, $tokens] = $this->authorize($user, $organization, 'stores:read');
        $oldAccessToken = $tokens['access_token'];

        $refreshed = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $tokens['refresh_token'],
            'resource' => app(CodexOAuthService::class)->resource(),
        ])->assertOk();
        $newAccessToken = $refreshed->json('access_token');
        $this->assertNotSame($oldAccessToken, $newAccessToken);
        $this->assertDatabaseCount('codex_api_tokens', 1);
        $this->withToken($oldAccessToken)->postJson('/mcp/decoadmin', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping',
        ])->assertUnauthorized();
        $this->withToken($newAccessToken)->postJson('/mcp/decoadmin', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping',
        ])->assertOk();

        $this->post('/oauth/revoke', ['token' => $tokens['refresh_token'], 'client_id' => $clientId])->assertOk();
        $this->withToken($newAccessToken)->postJson('/mcp/decoadmin', [
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping',
        ])->assertUnauthorized();
        $this->assertNotNull(CodexOAuthRefreshToken::query()->latest('id')->firstOrFail()->revoked_at);
    }

    public function test_remote_mcp_challenges_unauthenticated_clients_and_rechecks_rbac(): void
    {
        [$user, $organization, $store, $role] = $this->context();
        [, $tokens] = $this->authorize($user, $organization, 'stores:read dashboard:read');

        $this->postJson('/mcp/decoadmin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');

        $user->roles()->detach($role->id);
        $this->withToken($tokens['access_token'])->postJson('/mcp/decoadmin', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'decoadmin_get_dashboard',
                'arguments' => ['store_id' => $store->id, 'days' => 30],
            ],
        ])->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertSee('权限不足', false);
    }

    public function test_remote_mcp_write_still_requires_separate_confirmation_and_is_idempotent(): void
    {
        [$user, $organization, $store] = $this->context();
        [, $tokens] = $this->authorize($user, $organization, implode(' ', CodexApiToken::SUPPORTED_ABILITIES));
        $versions = app(AnalyticsCacheVersionService::class);

        $prepared = $this->withToken($tokens['access_token'])->postJson('/mcp/decoadmin', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'decoadmin_prepare_analytics_refresh',
                'arguments' => ['store_id' => $store->id, 'idempotency_key' => 'remote-refresh-001'],
            ],
        ])->assertOk()
            ->assertJsonPath('result.isError', null)
            ->assertJsonPath('result.structuredContent.data.confirmation.status', 'pending')
            ->assertSee('本轮不得执行', false);
        $confirmationId = $prepared->json('result.structuredContent.data.confirmation.id');
        $this->assertSame(1, $versions->current($store->id));

        $executed = [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'decoadmin_execute_confirmed_action',
                'arguments' => ['confirmation_id' => $confirmationId, 'confirmation_text' => '确认执行'],
            ],
        ];
        $this->withToken($tokens['access_token'])->postJson('/mcp/decoadmin', $executed)
            ->assertOk()
            ->assertJsonPath('result.structuredContent.data.idempotent_replay', false);
        $this->assertSame(2, $versions->current($store->id));

        $this->withToken($tokens['access_token'])->postJson('/mcp/decoadmin', $executed)
            ->assertOk()
            ->assertJsonPath('result.structuredContent.data.idempotent_replay', true);
        $this->assertSame(2, $versions->current($store->id));
    }

    public function test_remote_mcp_missing_store_error_is_sanitized(): void
    {
        [$user, $organization] = $this->context();
        [, $tokens] = $this->authorize($user, $organization, 'stores:read dashboard:read');

        $response = $this->withToken($tokens['access_token'])->postJson('/mcp/decoadmin', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'decoadmin_get_dashboard',
                'arguments' => ['store_id' => 999999, 'days' => 30],
            ],
        ])->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertSee('目标资源不存在或当前用户无权访问', false);
        $response->assertDontSee('App\\Models\\Store', false)
            ->assertDontSee('ModelNotFoundException', false);
    }

    /** @return array{0: User, 1: Organization, 2: Store, 3: Role} */
    private function context(): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'DecoMKT', 'code' => 'decomkt', 'status' => 'active']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Macfox Bike', 'shopify_domain' => 'macfox-oauth.myshopify.com', 'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', 'organization-admin')->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store, $role];
    }

    private function registerClient(): string
    {
        return $this->postJson('/oauth/register', [
            'client_name' => 'DecoAdmin Codex test',
            'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ])->assertSuccessful()->json('client_id');
    }

    /** @return array<string, string> */
    private function authorizationRequest(string $clientId, string $verifier, string $scope): array
    {
        return [
            'client_id' => $clientId,
            'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
            'response_type' => 'code',
            'scope' => $scope,
            'state' => 'oauth-state-001',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => app(CodexOAuthService::class)->resource(),
        ];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function authorize(User $user, Organization $organization, string $scope): array
    {
        $clientId = $this->registerClient();
        $verifier = str_repeat('r', 64);
        $request = $this->authorizationRequest($clientId, $verifier, $scope);
        $redirect = $this->actingAs($user)->post('/oauth/authorize', [
            ...$request, 'decision' => 'approve', 'organization_id' => $organization->id,
        ])->headers->get('Location');
        parse_str((string) parse_url((string) $redirect, PHP_URL_QUERY), $parameters);
        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'code' => $parameters['code'],
            'redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect',
            'code_verifier' => $verifier,
            'resource' => app(CodexOAuthService::class)->resource(),
        ])->assertOk()->json();

        return [$clientId, $tokens];
    }
}
