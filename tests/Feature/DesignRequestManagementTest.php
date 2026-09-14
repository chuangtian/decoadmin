<?php

namespace Tests\Feature;

use App\Models\DesignRequest;
use App\Models\DesignRequestAttachment;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
                ->where('canViewOverview', false)
                ->where('summary.total', 1)
                ->where('summary.completed', 1)
                ->has('requests.data', 1)
                ->where('requests.data.0.requester', $reporter->name)
                ->where('requests.data.0.description', '王静彬的首页 Banner'));

        $this->assertStringNotContainsString('王娇阳的 EDM', $this->get(route('design-requests.index'))->getContent());

        $viewerRole = $reporter->roles()->where('roles.slug', 'viewer')->firstOrFail();
        $marketingRole = Role::query()->whereBelongsTo($organization)->where('slug', 'marketing')->firstOrFail();
        $reporter->roles()->detach($viewerRole->id);
        $reporter->roles()->attach($marketingRole, ['organization_id' => $organization->id, 'store_id' => null]);

        $this->actingAs($reporter)->withSession($this->contextSession($organization, $store))
            ->get(route('design-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('requests.data.0.requester_role', '营销人员'));

        $this->get(route('design-requests.index', ['tab' => 'overview']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.tab', 'list')
                ->where('canViewOverview', false));
    }

    public function test_legacy_date_only_completion_never_produces_negative_turnaround(): void
    {
        CarbonImmutable::setTestNow('2026-09-12 10:00:00');
        [$designer, $organization, $store] = $this->context('designer');
        $requester = $designer;
        $item = $this->designRequest($organization, $store, $requester, [
            'designer_id' => $designer->id,
            'status' => 'completed',
            'planned_delivery_date' => '2026-09-12',
            'actual_delivery_date' => '2026-09-12',
        ]);
        $item->forceFill([
            'accepted_at' => null,
            'delivery_submitted_at' => '2026-09-12 00:00:00',
        ])->save();

        $this->actingAs($designer)->withSession($this->contextSession($organization, $store))
            ->get(route('design-requests.index', ['tab' => 'overview']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.average_turnaround_days', 0));
    }

    public function test_only_designers_developers_and_super_admins_see_all_requests_and_overview(): void
    {
        [$reporter, $organization, $store] = $this->context('viewer');
        $this->designRequest($organization, $store, $reporter, ['description' => '员工自己的任务']);

        foreach (['designer', 'developer', 'super-admin'] as $roleSlug) {
            [$user] = $this->additionalUser($organization, $store, $roleSlug, $roleSlug);
            $this->actingAs($user)->withSession($this->contextSession($organization, $store))
                ->get(route('design-requests.index', ['tab' => 'overview']))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('scope', 'all')
                    ->where('canViewOverview', true)
                    ->where('filters.tab', 'overview')
                    ->where('summary.total', 1));
        }

        foreach (['organization-admin', 'store-admin', 'operator', 'marketing'] as $roleSlug) {
            [$user] = $this->additionalUser($organization, $store, $roleSlug, $roleSlug);
            $this->actingAs($user)->withSession($this->contextSession($organization, $store))
                ->get(route('design-requests.index', ['tab' => 'overview']))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('scope', 'mine')
                    ->where('canViewOverview', false)
                    ->where('canManage', false)
                    ->where('filters.tab', 'list')
                    ->where('summary.total', 0));
        }
    }

    public function test_designer_submits_delivery_and_requester_confirms_completion(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 10:00:00');
        [$designer, $organization, $store] = $this->context('designer');
        [$otherDesigner] = $this->additionalUser($organization, $store, 'designer', '另一位设计师');
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
                ->where('canViewOverview', true)
                ->where('summary.total', 2)
                ->has('requests.data', 2)
                ->where('requests.data.1.can_accept', true)
                ->where('requests.data.1.can_process', false));

        $this->post(route('design-requests.accept', $item))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame($designer->id, $item->designer_id);
        $this->assertSame('assigned', $item->status);
        $this->assertNotNull($item->accepted_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'design_request_accepted', 'subject_id' => $item->id]);

        $this->actingAs($designer)->withSession($this->contextSession($organization, $store))
            ->get(route('design-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('requests.data.1.can_accept', false)
                ->where('requests.data.1.can_process', true));

        $this->actingAs($otherDesigner)->withSession($this->contextSession($organization, $store))
            ->get(route('design-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('requests.data.1.can_accept', false)
                ->where('requests.data.1.can_process', false));
        $this->post(route('design-requests.accept', $item))
            ->assertRedirect()
            ->assertSessionHasErrors('accept');
        $this->put(route('design-requests.update', $item), [
            'action' => 'progress',
            'progress_note' => '尝试越权提交进展',
        ])->assertForbidden();

        $this->actingAs($designer)->withSession($this->contextSession($organization, $store));
        $this->put(route('design-requests.update', $item), [
            'action' => 'progress',
            'progress_note' => '初稿已经完成',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $item->fresh()->status);
        $this->assertDatabaseHas('design_request_progress_logs', [
            'design_request_id' => $item->id,
            'action' => 'progress',
            'content' => '初稿已经完成',
        ]);

        $this->put(route('design-requests.update', $item), [
            'action' => 'submit_delivery',
            'progress_note' => '文件已交付',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame($designer->id, $item->designer_id);
        $this->assertSame('review', $item->status);
        $this->assertNotNull($item->delivery_submitted_at);
        $this->assertNull($item->actual_delivery_date);
        $this->assertDatabaseHas('design_request_progress_logs', [
            'design_request_id' => $item->id,
            'action' => 'delivery_submitted',
            'content' => '文件已交付',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'design_request_delivery_submitted', 'subject_id' => $item->id]);

        $this->put(route('design-requests.update', $item), [
            'action' => 'progress',
            'progress_note' => '等待期间继续修改',
        ])->assertStatus(409);

        $this->actingAs($first)->withSession($this->contextSession($organization, $store));
        $first->update(['timezone' => 'Asia/Shanghai']);
        $this->put(route('design-requests.review', $item), [
            'action' => 'confirm',
            'review_note' => '验收通过',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('completed', $item->status);
        $this->assertSame('2026-09-11', $item->actual_delivery_date->toDateString());
        $this->assertNotNull($item->reviewed_at);
        $this->assertNotNull($item->completed_at);
        $this->assertSame('2026-09-11T10:00:00+00:00', $item->reviewed_at->toIso8601String());
        $this->assertSame('2026-09-11T10:00:00+00:00', $item->completed_at->toIso8601String());
        $this->assertDatabaseHas('design_request_progress_logs', [
            'design_request_id' => $item->id,
            'action' => 'completed',
            'content' => '验收通过',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'design_request_confirmed', 'subject_id' => $item->id]);

        $this->actingAs($designer)->withSession($this->contextSession($organization, $store))
            ->get(route('design-requests.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('requests.data.1.status', 'completed')
                ->where('requests.data.1.revision_count', 0)
                ->where('requests.data.1.can_process', false)
                ->where('requests.data.1.can_review', false)
                ->has('requests.data.1.progress_logs', 4));
    }

    public function test_reporter_creation_forces_logged_in_requester_and_safe_initial_state(): void
    {
        CarbonImmutable::setTestNow('2026-09-09 10:00:00');
        Storage::fake('local');
        [$reporter, $organization, $store] = $this->context('viewer');
        [$other] = $this->additionalUser($organization, $store, 'viewer', '另一个用户');

        $this->actingAs($reporter)->withSession($this->contextSession($organization, $store))
            ->post(route('design-requests.store'), [
                'requester_id' => $other->id,
                'task_name' => '新品首页视觉设计',
                'request_type' => 'site_banner', 'priority' => 'urgent',
                'description' => '新品首页首屏 Banner', 'requester_department' => '品牌部',
                'quantity' => 2, 'planned_delivery_date' => '2026-09-12',
                'status' => 'completed', 'designer_id' => $other->id,
                'images' => [UploadedFile::fake()->image('brief.png', 320, 180)],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $item = DesignRequest::query()->sole();
        $attachment = DesignRequestAttachment::query()->sole();
        $this->assertSame($reporter->id, $item->requester_id);
        $this->assertNull($item->store_id);
        $this->assertSame('新品首页视觉设计', $item->task_name);
        $this->assertNull($item->requester_department);
        $this->assertSame(1, $item->quantity);
        $this->assertNull($item->designer_id);
        $this->assertSame('pending', $item->status);
        $this->assertSame('2026-09-09', $item->requested_on->toDateString());
        Storage::disk('local')->assertExists($attachment->path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'design_request_created', 'subject_id' => $item->id]);

        $this->actingAs($reporter)->withSession($this->contextSession($organization, $store))
            ->get(route('design-requests.attachments.show', [$item, $attachment]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
        $this->actingAs($other)->withSession($this->contextSession($organization, $store))
            ->get(route('design-requests.attachments.show', [$item, $attachment]))
            ->assertNotFound();
    }

    public function test_requester_can_request_revision_and_kpi_workload_stays_with_assigned_designer(): void
    {
        CarbonImmutable::setTestNow('2026-09-11 10:00:00');
        [$designer, $organization, $store] = $this->context('designer');
        [$reporter] = $this->additionalUser($organization, $store, 'viewer', '提报人');
        [$other] = $this->additionalUser($organization, $store, 'viewer', '其他员工');
        $item = $this->designRequest($organization, $store, $reporter, ['planned_delivery_date' => '2026-09-12']);

        $this->actingAs($designer)->withSession($this->contextSession($organization, $store));
        $this->post(route('design-requests.accept', $item))->assertRedirect()->assertSessionHasNoErrors();
        $this->put(route('design-requests.update', $item), [
            'action' => 'submit_delivery',
            'progress_note' => '第一版交付',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($other)->withSession($this->contextSession($organization, $store))
            ->put(route('design-requests.review', $item), [
                'action' => 'revision',
                'review_note' => '无权要求改稿',
            ])->assertNotFound();

        $this->actingAs($reporter)->withSession($this->contextSession($organization, $store))
            ->put(route('design-requests.review', $item), [
                'action' => 'revision',
                'review_note' => '请调整移动端文字间距',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame('in_progress', $item->status);
        $this->assertSame(1, $item->revision_count);
        $this->assertDatabaseHas('design_request_progress_logs', [
            'design_request_id' => $item->id,
            'action' => 'revision_requested',
            'content' => '请调整移动端文字间距',
        ]);

        $this->put(route('design-requests.review', $item), [
            'action' => 'confirm',
        ])->assertStatus(409);

        CarbonImmutable::setTestNow('2026-09-12 09:00:00');
        $this->actingAs($designer)->withSession($this->contextSession($organization, $store))
            ->put(route('design-requests.update', $item), [
                'action' => 'submit_delivery',
                'progress_note' => '第二版交付',
            ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($reporter)->withSession($this->contextSession($organization, $store))
            ->put(route('design-requests.review', $item), [
                'action' => 'confirm',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($designer)->withSession($this->contextSession($organization, $store))
            ->get(route('design-requests.index', ['tab' => 'overview']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.designer_performance.0.designer_id', $designer->id)
                ->where('summary.designer_performance.0.completed', 1)
                ->where('summary.designer_performance.0.on_time_rate', 100)
                ->where('summary.designer_performance.0.average_revisions', 1));
    }

    public function test_permissions_block_reporter_updates_but_developer_can_handle_legacy_store_requests(): void
    {
        [$reporter, $organization, $store] = $this->context('viewer');
        [$admin] = $this->additionalUser($organization, $store, 'developer', '开发人员');
        $otherStore = $organization->stores()->create(['name' => 'Other Store', 'shopify_domain' => 'other-design.test', 'status' => 'active']);
        $otherStore->members()->attach($admin, ['status' => 'active', 'joined_at' => now()]);
        $item = $this->designRequest($organization, $otherStore, $reporter, ['description' => '其他店铺需求']);
        $payload = ['action' => 'progress', 'progress_note' => '开始处理历史需求'];

        $this->actingAs($reporter)->withSession($this->contextSession($organization, $store))
            ->put(route('design-requests.update', $item), $payload)->assertForbidden();
        $this->actingAs($admin)->withSession($this->contextSession($organization, $store))
            ->post(route('design-requests.accept', $item))->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($admin)->withSession($this->contextSession($organization, $store))
            ->put(route('design-requests.update', $item), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $item->fresh()->status);
        $this->assertSame($admin->id, $item->fresh()->designer_id);
    }

    public function test_switching_stores_does_not_change_personal_design_requests(): void
    {
        [$reporter, $organization, $store] = $this->context('viewer');
        $otherStore = $organization->stores()->create(['name' => 'Other Store', 'shopify_domain' => 'other-personal-design.test', 'status' => 'active']);
        $otherStore->members()->attach($reporter, ['status' => 'active', 'joined_at' => now()]);
        $role = $reporter->roles()->firstOrFail();
        $reporter->roles()->updateExistingPivot($role->id, ['store_id' => $store->id]);
        $first = $this->designRequest($organization, $store, $reporter, ['description' => '原店铺创建的历史需求']);
        $second = $this->designRequest($organization, $otherStore, $reporter, ['description' => '另一店铺创建的历史需求']);

        foreach ([$store, $otherStore] as $selectedStore) {
            $this->actingAs($reporter)->withSession($this->contextSession($organization, $selectedStore))
                ->get(route('design-requests.index'))
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('summary.total', 2)
                    ->where('requests.data.0.uuid', $second->uuid)
                    ->where('requests.data.1.uuid', $first->uuid)
                    ->missing('store'));
        }
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
            'task_name' => '设计任务', 'request_type' => 'site_banner', 'priority' => 'medium', 'description' => '设计需求',
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
