<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BrandSocialPostState;
use App\Models\FeishuBitableRecord;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Services\NaturalTraffic\NaturalTrafficDashboardService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BrandSocialCsvImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_platform_template_round_trips_through_its_import_entry(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $this->actingAs($user)->withSession($this->contextSession($organization, $store));

        foreach (['instagram' => '账户编号', 'facebook' => '公共主页编号'] as $platform => $requiredHeader) {
            $download = $this->get(route('natural-traffic.brand-media.import-template', ['platform' => $platform]))
                ->assertOk()->assertDownload("decoadmin-{$platform}-template.csv");
            $csv = $download->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
            $this->assertStringContainsString($requiredHeader, $csv);
            $this->post(route('natural-traffic.brand-media.import'), [
                'platform' => $platform,
                'file' => UploadedFile::fake()->createWithContent($platform.'.csv', $csv),
            ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
            $record = FeishuBitableRecord::query()->where('source_record_id', 'post:'.($platform === 'instagram' ? 'ig' : 'fb').'-example-001')->sole();
            $this->assertSame(ucfirst($platform), $record->fields_encrypted['平台']);
        }
        $this->assertDatabaseCount('feishu_bitable_records', 2);
    }

    public function test_platform_import_rejects_a_file_for_the_other_platform_without_writes(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $this->actingAs($user)->withSession($this->contextSession($organization, $store));
        foreach (['instagram' => 'facebook', 'facebook' => 'instagram'] as $source => $destination) {
            $csv = $this->get(route('natural-traffic.brand-media.import-template', ['platform' => $source]))
                ->assertOk()->streamedContent();
            $this->post(route('natural-traffic.brand-media.import'), [
                'platform' => $destination,
                'file' => UploadedFile::fake()->createWithContent('wrong-platform.csv', $csv),
            ])->assertRedirect()->assertSessionHas('error');
        }
        $this->assertDatabaseCount('feishu_bitable_records', 0);
        $this->assertDatabaseCount('feishu_bitable_tables', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_meta_csv_import_is_idempotent_and_applies_instagram_only_outlier_policy(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $session = $this->contextSession($organization, $store);

        $instagram = <<<'CSV'
帖子编号,账户编号,账户账号,账户名称,描述,时长（秒）,发布时间,固定链接,帖子类型,日期,浏览量,覆盖人数,赞,分享,关注者数,评论,收藏次数,Instagram 新增关注人数
ig-regular,account-1,macfox,Macfox,常规 Reels,15,08/15/2026 16:00,https://instagram.test/regular,Reels,08/15/2026,50000,40000,500,20,1000,30,12,4
ig-viral,account-1,macfox,Macfox,爆款 Reels,18,08/14/2026 16:00,https://instagram.test/viral,Reels,08/14/2026,271640,200000,6000,400,1000,300,90,30
ig-image,account-1,macfox,Macfox,高覆盖图片,0,08/13/2026 16:00,https://instagram.test/image,图片,08/13/2026,20000,120001,800,40,1000,50,25,10
CSV;
        $facebook = <<<'CSV'
帖子编号,公共主页编号,公共主页名称,标题,描述,时长（秒）,发布时间,固定链接,帖子类型,日期,观看量,覆盖人数,心情、评论和分享,心情,评论数,分享次数,总点击量
fb-large,page-1,Macfox,FB 大流量内容,FB 数据不应用 IG 规则,12,08/12/2026 16:00,https://facebook.test/large,视频,08/12/2026,180000,150000,1600,1200,250,150,900
CSV;

        $this->actingAs($user)->withSession($session)
            ->post(route('natural-traffic.brand-media.import'), [
                'file' => UploadedFile::fake()->createWithContent('instagram.csv', $instagram),
            ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->actingAs($user)->withSession($session)
            ->post(route('natural-traffic.brand-media.import'), [
                'file' => UploadedFile::fake()->createWithContent('facebook.csv', $facebook),
            ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(4, FeishuBitableRecord::query()->count());
        $this->assertStringNotContainsString('常规 Reels', (string) DB::table('feishu_bitable_records')->pluck('fields_encrypted')->implode(' '));

        $dashboard = app(NaturalTrafficDashboardService::class)->forChannel($store, 'brand-media', [
            'date_from' => '2026-08-09',
            'date_to' => '2026-08-15',
            'comparison' => 'none',
        ]);
        $this->assertSame(4.0, $dashboard['kpis'][0]['value']);
        $this->assertSame(521640.0, $dashboard['kpis'][1]['value']);
        $this->assertSame(521640.0, $dashboard['funnel'][0]['value']);
        $this->assertSame(2.0, $dashboard['exclusion_summary']['posts']);
        $this->assertSame(1.0, $dashboard['selected_weekly_report']['included_posts']);
        $this->assertSame(50000.0, $dashboard['selected_weekly_report']['included_views']);
        $this->assertCount(3, $dashboard['platforms']);
        $this->assertSame(['Instagram', 'Facebook', 'YouTube'], array_column($dashboard['platforms'], 'platform'));
        $this->assertTrue($dashboard['platform_sources'][0]['available']);
        $this->assertTrue($dashboard['platform_sources'][1]['available']);
        $this->assertFalse($dashboard['platform_sources'][2]['available']);

        $posts = collect($dashboard['posts'])->keyBy('record_id');
        $this->assertTrue($posts['post:ig-viral']['excluded_from_aggregates']);
        $this->assertSame('播放量', $posts['post:ig-viral']['exclusion_metric']);
        $this->assertTrue($posts['post:ig-image']['excluded_from_aggregates']);
        $this->assertSame('覆盖人数', $posts['post:ig-image']['exclusion_metric']);
        $this->assertFalse($posts['post:fb-large']['excluded_from_aggregates']);

        $this->actingAs($user)->withSession($session)
            ->post(route('natural-traffic.brand-media.import'), [
                'file' => UploadedFile::fake()->createWithContent('instagram.csv', $instagram),
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(4, FeishuBitableRecord::query()->count());
        $latestAudit = AuditLog::query()->where('action', 'brand_social_csv_imported')->latest('id')->firstOrFail();
        $this->assertSame(0, $latestAudit->metadata['created']);
        $this->assertSame(0, $latestAudit->metadata['updated']);
        $this->assertSame(3, $latestAudit->metadata['unchanged']);
        $this->assertSame(0, $latestAudit->metadata['skipped_stale']);
    }

    public function test_user_without_sync_permission_cannot_import_brand_social_csv(): void
    {
        [$user, $organization, $store] = $this->context('operator');

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->post(route('natural-traffic.brand-media.import'), [
                'file' => UploadedFile::fake()->createWithContent('instagram.csv', '帖子编号'),
            ])->assertForbidden();

        $this->assertDatabaseCount('feishu_bitable_records', 0);
    }

    public function test_incremental_import_preserves_history_updates_newer_snapshot_and_skips_late_old_file(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $session = $this->contextSession($organization, $store);
        $initial = <<<'CSV'
帖子编号,账户编号,发布时间,帖子类型,浏览量,覆盖人数,赞,数据更新时间
history,account-1,08/10/2026 10:00,Reels,50,40,5,08/18/2026 10:00
current,account-1,08/11/2026 10:00,Reels,100,80,10,08/18/2026 10:00
CSV;
        $newer = <<<'CSV'
帖子编号,账户编号,发布时间,帖子类型,浏览量,覆盖人数,赞,数据更新时间
current,account-1,08/11/2026 10:00,Reels,200,160,20,08/20/2026 10:00
CSV;
        $stale = <<<'CSV'
帖子编号,账户编号,发布时间,帖子类型,浏览量,覆盖人数,赞,数据更新时间
current,account-1,08/11/2026 10:00,Reels,150,120,15,08/19/2026 10:00
CSV;

        foreach ([['initial.csv', $initial], ['newer.csv', $newer]] as [$name, $contents]) {
            $this->actingAs($user)->withSession($session)
                ->post(route('natural-traffic.brand-media.import'), [
                    'file' => UploadedFile::fake()->createWithContent($name, $contents),
                ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(2, FeishuBitableRecord::query()->count());
        $record = FeishuBitableRecord::query()->where('source_record_id', 'post:current')->sole();
        $this->assertSame('200', $record->fields_encrypted['浏览量']);
        $this->assertSame('2026-08-20 10:00:00', $record->source_updated_at->toDateTimeString());
        $updatedAudit = AuditLog::query()->where('action', 'brand_social_csv_imported')->latest('id')->firstOrFail();
        $this->assertSame(0, $updatedAudit->metadata['created']);
        $this->assertSame(1, $updatedAudit->metadata['updated']);
        $this->assertSame(0, $updatedAudit->metadata['unchanged']);
        $this->assertSame(0, $updatedAudit->metadata['skipped_stale']);

        $this->actingAs($user)->withSession($session)
            ->post(route('natural-traffic.brand-media.import'), [
                'file' => UploadedFile::fake()->createWithContent('late-old.csv', $stale),
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('200', $record->fresh()->fields_encrypted['浏览量']);
        $staleAudit = AuditLog::query()->where('action', 'brand_social_csv_imported')->latest('id')->firstOrFail();
        $this->assertSame(0, $staleAudit->metadata['updated']);
        $this->assertSame(1, $staleAudit->metadata['skipped_stale']);
        $this->assertDatabaseHas('feishu_bitable_records', ['source_record_id' => 'post:history']);
    }

    public function test_same_csv_duplicate_uses_newest_snapshot_and_same_post_id_is_scoped_by_platform(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $session = $this->contextSession($organization, $store);
        $instagram = <<<'CSV'
帖子编号,账户编号,发布时间,帖子类型,浏览量,覆盖人数,赞,数据更新时间
shared-id,account-1,08/11/2026 10:00,Reels,100,80,10,08/18/2026 10:00
shared-id,account-1,08/11/2026 10:00,Reels,250,200,25,08/20/2026 10:00
CSV;
        $facebook = <<<'CSV'
帖子编号,公共主页编号,发布时间,帖子类型,观看量,覆盖人数,心情,数据更新时间
shared-id,page-1,08/11/2026 10:00,视频,400,300,40,08/20/2026 11:00
CSV;

        $this->actingAs($user)->withSession($session)
            ->post(route('natural-traffic.brand-media.import'), [
                'file' => UploadedFile::fake()->createWithContent('instagram-duplicates.csv', $instagram),
            ])->assertRedirect()->assertSessionHasNoErrors();

        $duplicateAudit = AuditLog::query()->where('action', 'brand_social_csv_imported')->latest('id')->firstOrFail();
        $this->assertSame(2, $duplicateAudit->metadata['rows']);
        $this->assertSame(1, $duplicateAudit->metadata['unique_rows']);
        $this->assertSame(1, $duplicateAudit->metadata['created']);
        $this->assertSame(1, $duplicateAudit->metadata['skipped_stale']);
        $instagramRecord = FeishuBitableRecord::query()->sole();
        $this->assertSame('250', $instagramRecord->fields_encrypted['浏览量']);

        $this->actingAs($user)->withSession($session)
            ->post(route('natural-traffic.brand-media.import'), [
                'file' => UploadedFile::fake()->createWithContent('facebook.csv', $facebook),
            ])->assertRedirect()->assertSessionHasNoErrors();

        $records = FeishuBitableRecord::query()->where('source_record_id', 'post:shared-id')->get();
        $this->assertCount(2, $records);
        $this->assertEqualsCanonicalizing(['Instagram', 'Facebook'], $records->pluck('fields_encrypted')->map(fn (array $fields): string => $fields['平台'])->all());
        $this->assertSame(2, $records->pluck('feishu_bitable_table_id')->unique()->count());
    }

    public function test_decoadmin_template_imports_cross_platform_origin_and_visibility_without_id_collisions(): void
    {
        [$user, $organization, $store] = $this->context('store-admin');
        $csv = <<<'CSV'
平台,帖子编号,发布时间,帖子类型,内容来源,账户账号,描述,浏览量,赞,评论数,分享,固定链接,显示状态,数据更新时间
Instagram,shared,09/01/2026 09:00,Reels,官媒内容,macfoxbike,Official,100,10,2,1,https://instagram.test/shared,可见,09/02/2026 09:00
Facebook,shared,09/01/2026 09:00,Video,合作内容,creator,Collaboration,200,20,3,2,https://facebook.test/shared,已隐藏,09/02/2026 09:00
CSV;

        $this->actingAs($user)->withSession($this->contextSession($organization, $store))
            ->post(route('natural-traffic.brand-media.import'), [
                'file' => UploadedFile::fake()->createWithContent('decoadmin.csv', $csv),
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseCount('feishu_bitable_records', 2);
        $this->assertDatabaseCount('brand_social_post_states', 2);
        $this->assertTrue(BrandSocialPostState::query()->where('source_record_id', 'post:facebook:shared')->sole()->is_hidden);
        $dashboard = app(NaturalTrafficDashboardService::class)->forChannel($store, 'brand-media', [
            'date_from' => '2026-09-01', 'date_to' => '2026-09-01', 'comparison' => 'none',
            'content_visibility' => 'all',
        ]);

        $this->assertSame(1.0, $dashboard['kpis'][0]['value']);
        $this->assertSame(100.0, $dashboard['kpis'][1]['value']);
        $this->assertSame(1, $dashboard['hidden_posts_count']);
        $posts = collect($dashboard['posts'])->keyBy('record_id');
        $this->assertSame('official', $posts['post:instagram:shared']['content_origin']);
        $this->assertSame('collaboration', $posts['post:facebook:shared']['content_origin']);
    }

    /** @return array{User, Organization, Store} */
    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $organization = Organization::query()->create(['name' => 'Brand Social', 'code' => 'brand-social']);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Brand Social Store',
            'shopify_domain' => 'brand-social.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    /** @return array{current_organization_id: int, current_store_id: int} */
    private function contextSession(Organization $organization, Store $store): array
    {
        return ['current_organization_id' => $organization->id, 'current_store_id' => $store->id];
    }
}
