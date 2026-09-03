<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BrandSocialDailyReview;
use App\Models\BrandSocialPostState;
use App\Models\BrandSocialWeeklyReport;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandSocialWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_operator_can_hide_and_restore_one_scoped_post_idempotently(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $this->createSocialPost($organization, $store, 'post-one', [
            '发布日期' => '2026-08-18', '平台' => 'Instagram', '描述' => 'A post',
            '帖子类型' => 'Reels', '浏览量' => 150000, '点赞' => 1000, '评论数' => 100,
        ]);
        $payload = [
            'source_section' => 'natural-traffic:social',
            'source_table_key' => 'social-table',
            'source_record_id' => 'post-one',
            'hidden' => true,
        ];

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->put(route('natural-traffic.brand-media.posts.visibility'), $payload)
            ->assertRedirect();
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->put(route('natural-traffic.brand-media.posts.visibility'), $payload)
            ->assertRedirect();

        $this->assertDatabaseCount('brand_social_post_states', 1);
        $this->assertTrue(BrandSocialPostState::query()->sole()->is_hidden);
        $this->assertSame(true, AuditLog::query()->where('action', 'brand_social_post_visibility_updated')->latest('id')->firstOrFail()->metadata['idempotent']);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('natural-traffic.brand-media', [
                'date_from' => '2026-08-18', 'date_to' => '2026-08-18', 'comparison' => 'none',
                'content_visibility' => 'hidden',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dashboard.kpis.0.value', 0)
                ->where('dashboard.hidden_posts_count', 1)
                ->where('dashboard.posts.0.visibility_status', '已隐藏'));

        $payload['hidden'] = false;
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->put(route('natural-traffic.brand-media.posts.visibility'), $payload)
            ->assertRedirect();
        $this->assertFalse(BrandSocialPostState::query()->sole()->fresh()->is_hidden);
    }

    public function test_daily_reviews_and_weekly_reports_are_scoped_persisted_and_audited(): void
    {
        [$user, $organization, $store] = $this->context('operator');
        $this->createSocialPost($organization, $store, 'regular', [
            '发布日期' => '2026-08-18', '平台' => 'Instagram', '描述' => 'Regular',
            '帖子类型' => 'Reels', '浏览量' => 20000, '点赞' => 500, '评论数' => 30,
        ]);
        $this->createSocialPost($organization, $store, 'viral', [
            '发布日期' => '2026-08-19', '平台' => 'Instagram', '描述' => 'Viral',
            '帖子类型' => 'Reels', '浏览量' => 150000, '点赞' => 6000, '评论数' => 400,
        ]);

        $daily = [
            'review_date' => '2026-08-19', 'status' => 'published', 'core_data' => '核心数据',
            'top_content' => 'Viral', 'low_content' => 'Regular', 'recommendations' => '继续测试',
        ];
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->put(route('natural-traffic.brand-media.daily-reviews.upsert'), $daily)
            ->assertRedirect();
        $this->assertDatabaseCount('brand_social_daily_reviews', 1);
        $this->assertSame('published', BrandSocialDailyReview::query()->sole()->status);

        $weekly = ['week_start' => '2026-08-19', 'title' => 'Aug 17 – Aug 23, 2026', 'status' => 'published', 'summary' => '本周总结'];
        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->put(route('natural-traffic.brand-media.weekly-reports.upsert'), $weekly)
            ->assertRedirect();
        $report = BrandSocialWeeklyReport::query()->sole();
        $this->assertSame('2026-08-17', $report->week_start->toDateString());
        $this->assertEquals(1, $report->metrics_snapshot['included_posts']);
        $this->assertEquals(20000, $report->metrics_snapshot['included_views']);
        $this->assertEquals(1, $report->metrics_snapshot['excluded_posts']);

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->get(route('natural-traffic.brand-media', ['date_from' => '2026-08-17', 'date_to' => '2026-08-23']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManage', true)
                ->where('dashboard.daily_reviews.0.core_data', '核心数据')
                ->where('dashboard.selected_weekly_report.title', 'Aug 17 – Aug 23, 2026')
                ->where('dashboard.selected_weekly_report.included_posts', 1)
                ->where('dashboard.selected_weekly_report.excluded_posts', 1));

        $this->assertDatabaseHas('audit_logs', ['action' => 'brand_social_daily_review_created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'brand_social_weekly_report_created']);
    }

    public function test_read_only_user_cannot_manage_brand_social_records_or_cross_store_posts(): void
    {
        [$viewer, $organization, $store] = $this->context('viewer');
        $other = $organization->stores()->create(['name' => 'Other', 'shopify_domain' => 'other-brand.test', 'status' => 'active']);
        $this->createSocialPost($organization, $other, 'other-post', [
            '发布日期' => '2026-08-18', '平台' => 'Instagram', '描述' => 'Other', '浏览量' => 10,
        ]);
        $payload = [
            'source_section' => 'natural-traffic:social', 'source_table_key' => 'social-table',
            'source_record_id' => 'other-post', 'hidden' => true,
        ];

        $this->actingAs($viewer)->withSession($this->contextSession($organization, $store))
            ->put(route('natural-traffic.brand-media.posts.visibility'), $payload)
            ->assertForbidden();

        [$operator] = $this->additionalUser($organization, $store, 'operator');
        $this->actingAs($operator)->withSession($this->contextSession($organization, $store))
            ->put(route('natural-traffic.brand-media.posts.visibility'), $payload)
            ->assertNotFound();
        $this->assertDatabaseCount('brand_social_post_states', 0);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create(['name' => 'Brand Social', 'code' => 'brand-social']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create(['name' => 'Brand Store', 'shopify_domain' => 'brand.test', 'status' => 'active']);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    /** @return array{User} */
    private function additionalUser(Organization $organization, Store $store, string $roleSlug): array
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user];
    }

    /** @param array<string, mixed> $fields */
    private function createSocialPost(Organization $organization, Store $store, string $recordId, array $fields): void
    {
        $table = FeishuBitableTable::query()->firstOrCreate(
            ['store_id' => $store->id, 'source_section' => 'natural-traffic:social', 'source_table_id' => 'social-table'],
            ['organization_id' => $organization->id, 'name' => '官媒内容', 'metadata_encrypted' => [], 'synced_at' => now()],
        );
        FeishuBitableRecord::query()->create([
            'organization_id' => $organization->id, 'store_id' => $store->id,
            'feishu_bitable_table_id' => $table->id, 'source_record_id' => $recordId,
            'fields_encrypted' => $fields, 'synced_at' => now(),
        ]);
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
