<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditLogCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_view_audit_logs_for_the_current_organization(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $visible = $this->audit($organization, $store, $user, 'shopify_connection_connected');

        $otherOrganization = Organization::query()->create(['name' => 'Other Organization', 'code' => 'other']);
        $this->audit($otherOrganization, null, null, 'shopify_connection_invalid');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AuditLogs/Index')
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.id', $visible->id)
                ->where('summary.total', 1)
                ->has('options.actions', 1)
                ->has('options.stores', 1));
    }

    public function test_user_only_sees_audits_for_authorized_stores(): void
    {
        [$user, $organization, $authorizedStore] = $this->context('organization-admin');
        $unauthorizedStore = $organization->stores()->create([
            'name' => 'Restricted Store',
            'shopify_domain' => 'restricted-audit.myshopify.com',
            'status' => 'active',
        ]);

        $organizationAudit = $this->audit($organization, null, $user, 'role_updated');
        $authorizedAudit = $this->audit($organization, $authorizedStore, $user, 'shopify_connection_connected');
        $this->audit($organization, $unauthorizedStore, $user, 'shopify_connection_warning');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $authorizedStore))
            ->get(route('audit-logs.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('auditLogs.data', 2)
                ->where('summary.total', 2)
                ->where('options.stores.0.id', $authorizedStore->id)
                ->where('auditLogs.data', fn ($records): bool => collect($records)->pluck('id')->sort()->values()->all() === collect([$organizationAudit->id, $authorizedAudit->id])->sort()->values()->all()));
    }

    public function test_audit_filters_are_applied_inside_the_authorized_scope(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $matching = $this->audit($organization, $store, $user, 'shopify_connection_disconnected');
        $this->audit($organization, $store, $user, 'shopify_connection_connected');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('audit-logs.index', [
                'action' => 'shopify_connection_disconnected',
                'user_id' => $user->id,
                'store_id' => $store->id,
                'date_from' => now()->toDateString(),
                'date_to' => now()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('auditLogs.data', 1)
                ->where('auditLogs.data.0.id', $matching->id)
                ->where('filters.action', 'shopify_connection_disconnected')
                ->where('filters.user_id', $user->id)
                ->where('filters.store_id', $store->id));
    }

    public function test_user_without_audit_permission_cannot_view_index_or_detail(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $audit = $this->audit($organization, $store, $user, 'shopify_connection_connected');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('audit-logs.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('audit-logs.show', $audit))
            ->assertForbidden();
    }

    public function test_audit_detail_from_another_organization_is_not_found(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $otherOrganization = Organization::query()->create(['name' => 'Other Organization', 'code' => 'other']);
        $otherAudit = $this->audit($otherOrganization, null, null, 'shopify_connection_invalid');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('audit-logs.show', $otherAudit))
            ->assertNotFound();
    }

    public function test_audit_detail_masks_sensitive_fields_and_secret_patterns(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $audit = AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $user->id,
            'action' => 'shopify_connection_invalid',
            'ip_address' => '203.0.113.42',
            'old_values' => ['client_secret' => 'secret-before'],
            'new_values' => ['nested' => ['password' => 'secret-after']],
            'metadata' => [
                'access_token' => 'shpss_sensitive-token',
                'reason' => 'Authorization Bearer sensitive.authorization.token failed',
            ],
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('audit-logs.show', $audit))
            ->assertOk()
            ->assertDontSee('secret-before')
            ->assertDontSee('secret-after')
            ->assertDontSee('shpss_sensitive-token')
            ->assertDontSee('sensitive.authorization.token')
            ->assertInertia(fn (Assert $page) => $page
                ->component('AuditLogs/Show')
                ->where('auditLog.ip_address', '203.0.113.***')
                ->where('auditLog.old_values.client_secret', '[已隐藏]')
                ->where('auditLog.new_values.nested.password', '[已隐藏]')
                ->where('auditLog.metadata.access_token', '[已隐藏]')
                ->where('auditLog.metadata.reason', 'Authorization Bearer [已隐藏] failed'));
    }

    /** @return array{0: User, 1: Organization, 2: Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'DecoMKT', 'code' => 'decomkt']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Macfox US',
            'shopify_domain' => 'macfox-audit.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function audit(Organization $organization, ?Store $store, ?User $user, string $action): AuditLog
    {
        return AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store?->id,
            'user_id' => $user?->id,
            'action' => $action,
            'metadata' => ['reason' => 'Test audit event'],
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
