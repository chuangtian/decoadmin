<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
        $this->assertSame(2.0, $dashboard['kpis'][0]['value']);
        $this->assertSame(230000.0, $dashboard['kpis'][1]['value']);
        $this->assertSame(230000.0, $dashboard['funnel'][0]['value']);
        $this->assertSame(2.0, $dashboard['exclusion_summary']['posts']);
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
        $this->assertSame(3, $latestAudit->metadata['updated']);
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
