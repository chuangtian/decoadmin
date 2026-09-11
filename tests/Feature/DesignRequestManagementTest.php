<?php

namespace Tests\Feature;

use App\Models\DesignRequest;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DesignRequestManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_reporter_only_sees_own_requests_and_own_efficiency_summary(): void
    {
        CarbonImmutable::setTestNow('2026-09-09 10:00:00');
        [$reporter, $organization, $store] = $this->context('viewer');
        [$other] = $this->additionalUser($organization, $store, 'viewer', '王娇阳');
        $this->designRequest($organization, $store, $reporter, ['description' => '王静彬的首页 Banner', 'status' => 'completed', 'actual_delivery_date' => '2026-09-09']);
        $this->designRequest($organization, $store, $other, ['description' => '王娇阳的 EDM', 'planned_delivery_date' => '2026-09-08']);

        $this->actingAs($reporter)->withSession($this->contextSession($organization, $store))
            ->get(route('design-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('DesignRequests/Index')
                ->where('scope', 'mine')
                ->where('canManage', false)
                ->where('summary.total', 1)
                ->where('summary.completed', 1)
                ->has('requests.data', 1)
                ->where('requests.data.0.requester', $reporter->name)
                ->where('requests.data.0.description', '王静彬的首页 Banner'));

        $this->assertStringNotContainsString('王娇阳的 EDM', $this->get(route('design-requests.index'))->getContent());
    }

    public function test_designer_sees_all_store_requests_and_can_assign_and_complete_one(): void
    {
        [$designer, $organization, $store] = $this->context('designer');
        [$first] = $this->additionalUser($organization, $store, 'viewer', '王静彬');
        [$second] = $this->additionalUser($organization, $store, 'viewer', '王娇阳');
        $item = $this->designRequest($organization, $store, $first, ['description' => '第一条需求']);
        $this->designRequest($organization, $store, $second, ['description' => '第二条需求']);

        $this->actingAs($designer)->withSession($this->contextSession($organization, $store))
            ->get(route('design-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('scope', 'all')
                ->where('canManage', true)
                ->where('summary.total', 2)
                ->has('requests.data', 2)
                ->where('options.designers.0.id', $designer->id));

        $this->put(route('design-requests.update', $item), [
            'designer_id' => $designer->id,
            'status' => 'completed',
            'planned_delivery_date' => '2026-09-12',
            'actual_delivery_date' => '2026-09-11',
            'revision_count' => 2,
            'delivery_note' => '文件已交付',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame($designer->id, $item->designer_id);
        $this->assertSame('completed', $item->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'design_request_updated', 'subject_id' => $item->id]);
    }

    public function test_reporter_creation_forces_logged_in_requester_and_safe_initial_state(): void
    {
        CarbonImmutable::setTestNow('2026-09-09 10:00:00');
        [$reporter, $organization, $store] = $this->context('viewer');
        [$other] = $this->additionalUser($organization, $store, 'viewer', '另一个用户');

        $this->actingAs($reporter)->withSession($this->contextSession($organization, $store))
            ->post(route('design-requests.store'), [
                'requester_id' => $other->id,
                'request_type' => 'site_banner', 'priority' => 'urgent',
                'description' => '新品首页首屏 Banner', 'requester_department' => '品牌部',
                'quantity' => 2, 'planned_delivery_date' => '2026-09-12',
                'status' => 'completed', 'designer_id' => $other->id,
            ])->assertRedirect()->assertSessionHasNoErrors();

        $item = DesignRequest::query()->sole();
        $this->assertSame($reporter->id, $item->requester_id);
        $this->assertNull($item->designer_id);
        $this->assertSame('pending', $item->status);
        $this->assertSame('2026-09-09', $item->requested_on->toDateString());
        $this->assertDatabaseHas('audit_logs', ['action' => 'design_request_created', 'subject_id' => $item->id]);
    }

    public function test_permissions_block_reporter_updates_and_cross_store_manager_access(): void
    {
        [$reporter, $organization, $store] = $this->context('viewer');
        [$admin] = $this->additionalUser($organization, $store, 'store-admin', '管理员');
        $otherStore = $organization->stores()->create(['name' => 'Other Store', 'shopify_domain' => 'other-design.test', 'status' => 'active']);
        $otherStore->members()->attach($admin, ['status' => 'active', 'joined_at' => now()]);
        $item = $this->designRequest($organization, $otherStore, $reporter, ['description' => '其他店铺需求']);
        $payload = ['designer_id' => null, 'status' => 'in_progress', 'planned_delivery_date' => '2026-09-12', 'actual_delivery_date' => null, 'revision_count' => 0, 'delivery_note' => null];

        $this->actingAs($reporter)->withSession($this->contextSession($organization, $store))
            ->put(route('design-requests.update', $item), $payload)->assertForbidden();
        $this->actingAs($admin)->withSession($this->contextSession($organization, $store))
            ->put(route('design-requests.update', $item), $payload)->assertNotFound();
        $this->assertSame('pending', $item->fresh()->status);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $organization = Organization::query()->create(['name' => 'Design Team', 'code' => 'design-team']);
        $store = $organization->stores()->create(['name' => 'Macfox Bike', 'shopify_domain' => 'design.test', 'status' => 'active']);
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        [$user] = $this->additionalUser($organization, $store, $roleSlug, $roleSlug === 'designer' ? '设计师' : '王静彬');

        return [$user, $organization, $store];
    }

    /** @return array{User, Role} */
    private function additionalUser(Organization $organization, Store $store, string $roleSlug, string $name): array
    {
        $user = User::factory()->create(['name' => $name, 'email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $role];
    }

    private function designRequest(Organization $organization, Store $store, User $requester, array $values = []): DesignRequest
    {
        return DesignRequest::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id,
            'request_type' => 'site_banner', 'priority' => 'medium', 'description' => '设计需求',
            'requester_id' => $requester->id, 'quantity' => 1, 'requested_on' => '2026-09-09',
            'planned_delivery_date' => '2026-09-12', 'status' => 'pending',
            ...$values,
        ]);
    }

    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
