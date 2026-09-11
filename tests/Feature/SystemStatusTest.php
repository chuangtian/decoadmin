<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\SyncJob;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_user_can_view_the_sanitized_system_status_page(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.status'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('System/Status')
                ->where('systemStatus.summary.status', fn (string $status): bool => in_array($status, ['healthy', 'warning', 'degraded'], true))
                ->has('systemStatus.services', 6)
                ->has('systemStatus.queues', 9)
                ->has('systemStatus.incidents', 3)
                ->where('systemStatus.runtime.environment', app()->environment()));
    }

    public function test_user_without_health_permission_cannot_view_system_status(): void
    {
        [$user, $organization, $store] = $this->context('operator');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.status'))
            ->assertForbidden();
    }

    public function test_status_response_never_exposes_failed_job_or_sync_error_contents(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $secret = 'shpss_sensitive-system-status-secret';

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'shopify-sync',
            'payload' => json_encode(['access_token' => $secret]),
            'exception' => "Authorization failed with {$secret}",
            'failed_at' => now(),
        ]);
        $this->syncJob($organization, $store, $secret);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.status'))
            ->assertOk()
            ->assertDontSee($secret)
            ->assertInertia(fn (Assert $page) => $page
                ->where('systemStatus.summary.status', 'warning')
                ->where('systemStatus.incidents.0.count', 1)
                ->where('systemStatus.incidents.1.count', 1));
    }

    public function test_sync_incidents_are_limited_to_the_current_organization_and_accessible_stores(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $this->syncJob($organization, $store, 'visible-error');

        $otherOrganization = Organization::query()->create(['name' => 'Other Organization', 'code' => 'other']);
        $otherStore = $otherOrganization->stores()->create([
            'name' => 'Other Store',
            'shopify_domain' => 'other-system-status.myshopify.com',
            'status' => 'active',
        ]);
        $this->syncJob($otherOrganization, $otherStore, 'hidden-error');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.status'))
            ->assertOk()
            ->assertDontSee('hidden-error')
            ->assertInertia(fn (Assert $page) => $page
                ->where('systemStatus.incidents.1.count', 1));
    }

    public function test_queue_health_uses_recent_failures_while_retaining_a_sanitized_total(): void
    {
        Carbon::setTestNow('2026-08-26 12:00:00');
        [$user, $organization, $store] = $this->context('organization-admin');

        DB::table('failed_jobs')->insert([
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis',
                'queue' => 'shopify-webhook',
                'payload' => '{}',
                'exception' => 'old failure',
                'failed_at' => now()->subDays(2),
            ],
            [
                'uuid' => (string) Str::uuid(),
                'connection' => 'redis',
                'queue' => 'shopify-webhook',
                'payload' => '{}',
                'exception' => 'recent failure',
                'failed_at' => now()->subHour(),
            ],
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.status'))
            ->assertOk()
            ->assertDontSee('old failure')
            ->assertDontSee('recent failure')
            ->assertInertia(fn (Assert $page) => $page
                ->where('systemStatus.queues.0.name', 'shopify-webhook')
                ->where('systemStatus.queues.0.failed', 1)
                ->where('systemStatus.queues.0.failed_total', 2)
                ->where('systemStatus.queues.0.failed_window_hours', 24)
                ->where('systemStatus.queues.0.status', 'warning')
                ->where('systemStatus.incidents.0.count', 1));

        Carbon::setTestNow();
    }

    public function test_old_failed_jobs_do_not_keep_a_queue_in_warning_state_forever(): void
    {
        Carbon::setTestNow('2026-08-26 12:00:00');
        [$user, $organization, $store] = $this->context('organization-admin');

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'shopify-webhook',
            'payload' => '{}',
            'exception' => 'old failure',
            'failed_at' => now()->subDays(2),
        ]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.status'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('systemStatus.queues.0.name', 'shopify-webhook')
                ->where('systemStatus.queues.0.failed', 0)
                ->where('systemStatus.queues.0.failed_total', 1)
                ->where('systemStatus.queues.0.status', 'healthy')
                ->where('systemStatus.incidents.0.count', 0));

        Carbon::setTestNow();
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
            'shopify_domain' => 'macfox-system-status.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function syncJob(Organization $organization, Store $store, string $error): SyncJob
    {
        return SyncJob::query()->create([
            'uuid' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'type' => 'products',
            'direction' => 'pull',
            'status' => 'failed',
            'failed_at' => now(),
            'last_error' => $error,
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
