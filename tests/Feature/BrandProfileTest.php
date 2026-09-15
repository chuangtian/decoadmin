<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FeishuBitableTable;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\User;
use App\Services\BrandProfileService;
use App\Services\Feishu\FeishuBitableClient;
use App\Services\StoreFeishuDataLinkService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BrandProfileTest extends TestCase
{
    use RefreshDatabase;

    private const SECTIONS = ['overview', 'login-emails', 'seo-accounts', 'plugins', 'business-licenses'];

    public function test_replacing_license_image_preserves_original_bytes_pdf_and_replacement_after_sync(): void
    {
        Storage::fake('local');
        [$user, $organization, $store] = $this->context();
        $this->sourceFixture($store);
        $service = app(BrandProfileService::class);
        $service->sync($store);
        $originalAssets = $service->page($store, 'business-licenses')['assets'];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aGp8AAAAASUVORK5CYII=');
        $replacement = $service->replaceLicenseImage($store, $png);
        $service->sync($store);
        $assets = $service->page($store, 'business-licenses')['assets'];
        $this->assertCount(2, $assets);
        $this->assertSame($replacement['id'], $assets[0]['id']);
        $this->assertSame($originalAssets[1], $assets[1]);
        $this->assertSame($png, $service->file($store, $replacement['id'])['contents']);
        $this->assertArrayNotHasKey('path', $assets[0]);
        $this->assertSame('private', Storage::disk('local')->getVisibility('brand-profile/'.$organization->id.'/'.$store->id.'/licenses/'.$replacement['id'].'.png'));
        $this->get($assets[0]['url'])->assertRedirect('/login');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
            ->get($assets[0]['url'])->assertOk()->assertContent($png)->assertHeader('Content-Type', 'image/png');
        $this->get(str_replace('/'.$store->id.'/', '/999/', $assets[0]['url']))->assertNotFound();
        $this->assertSame(1, AuditLog::query()->where('action', 'brand_profile_license_image_replaced')->count());
    }

    public function test_invalid_image_does_not_create_a_license_replacement(): void
    {
        Storage::fake('local');
        [, , $store] = $this->context();
        try {
            app(BrandProfileService::class)->replaceLicenseImage($store, '<html>not an image</html>');
            $this->fail('Invalid image must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('有效', $exception->getMessage());
        }
        $this->assertSame(0, FeishuBitableTable::query()->where('source_section', 'brand_profile_overrides')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_synced_passwords_are_encrypted_masked_and_only_revealed_with_permission(): void
    {
        [$user, $organization, $store] = $this->context();
        $this->sourceFixture($store);
        $summary = app(BrandProfileService::class)->sync($store);
        $this->assertSame(1, $summary['login-emails']);
        $this->assertSame(2, $summary['business-licenses']);
        $snapshot = FeishuBitableTable::query()->where('source_table_id', 'login-emails')->firstOrFail();
        $this->assertStringNotContainsString('fixture-password', $snapshot->getRawOriginal('metadata_encrypted'));
        $profile = app(BrandProfileService::class)->page($store, 'login-emails');
        $this->assertSame(['secret' => true, 'configured' => true, 'text' => '', 'links' => []], $profile['rows'][0]['cells']['密码']);
        $row = $profile['rows'][0]['id'];
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id]);
        $this->get('/brand-profile/login-emails')->assertOk()->assertDontSee('fixture-password');
        $url = "/brand-profile/{$store->id}/login-emails/rows/{$row}/password";
        $this->getJson($url)->assertForbidden();
        $this->post("/brand-profile/{$store->id}/refresh")->assertForbidden();
        $user->roles()->firstOrFail()->permissions()->attach(Permission::query()->where('slug', 'store.update')->firstOrFail());
        $this->getJson($url)->assertOk()->assertJsonPath('data.value', ' fixture-password ')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, AuditLog::query()->where('action', 'brand_profile_password_viewed')->count());
        $this->assertStringNotContainsString('fixture-password', AuditLog::query()->get()->toJson());
        $this->getJson(str_replace('/'.$store->id.'/', '/999/', $url))->assertNotFound();
        $this->getJson(str_replace($row, str_repeat('0', 64), $url))->assertNotFound();
    }

    public function test_brand_profile_is_shared_with_sibling_stores_in_the_same_organization(): void
    {
        [$user, $organization, $sourceStore] = $this->context();
        $siblingStore = $organization->stores()->create([
            'name' => 'Example EU', 'shopify_domain' => 'example-eu.myshopify.com', 'status' => 'active',
        ]);
        $siblingStore->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $this->sourceFixture($sourceStore);
        $service = app(BrandProfileService::class);
        $service->sync($sourceStore);

        $profile = $service->page($siblingStore, 'login-emails');
        $this->assertTrue($profile['configured']);
        $this->assertTrue($profile['inherited']);
        $this->assertCount(1, $profile['rows']);
        $this->assertSame(0, FeishuBitableTable::query()->where('store_id', $siblingStore->id)->where('source_section', 'brand_profile')->count());

        $licenseProfile = $service->page($siblingStore, 'business-licenses');
        $this->assertTrue($licenseProfile['inherited']);
        $this->assertCount(2, $licenseProfile['assets']);

        $user->roles()->firstOrFail()->permissions()->attach(Permission::query()->where('slug', 'store.update')->firstOrFail());
        $rowId = $profile['rows'][0]['id'];
        $this->actingAs($user)->withSession([
            'current_organization_id' => $organization->id,
            'current_store_id' => $siblingStore->id,
        ])->getJson("/brand-profile/{$siblingStore->id}/login-emails/rows/{$rowId}/password")
            ->assertOk()
            ->assertJsonPath('data.value', ' fixture-password ');
        $this->get($licenseProfile['assets'][0]['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_brand_source_prefers_a_store_override_and_never_crosses_organizations(): void
    {
        [, $organization, $sourceStore] = $this->context();
        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $sourceStore->id,
            'provider' => 'feishu_data_links',
            'credential_key' => 'brand_spreadsheet_token',
            'credential_value' => 'shared-brand-source',
        ]);
        $siblingStore = $organization->stores()->create([
            'name' => 'Example EU', 'shopify_domain' => 'example-eu.myshopify.com', 'status' => 'active',
        ]);
        $otherOrganization = Organization::query()->create(['name' => 'Other', 'code' => 'other-brand']);
        $foreignStore = $otherOrganization->stores()->create([
            'name' => 'Foreign', 'shopify_domain' => 'foreign.myshopify.com', 'status' => 'active',
        ]);
        $links = app(StoreFeishuDataLinkService::class);

        $this->assertSame('shared-brand-source', $links->valuesForSync($siblingStore, 'brand')['brand_spreadsheet_token']);
        $this->assertSame([], $links->valuesForSync($foreignStore, 'brand'));
        $brandSettings = collect($links->catalogForFrontend($siblingStore, true))->firstWhere('key', 'brand');
        $this->assertTrue($brandSettings['inherited']);
        $this->assertTrue($brandSettings['fields'][0]['configured']);
        $this->assertSame('', $brandSettings['fields'][0]['masked_value']);
        $this->assertSame('', $links->reveal($siblingStore, 'brand', 'brand_spreadsheet_token'));

        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $siblingStore->id,
            'provider' => 'feishu_data_links',
            'credential_key' => 'brand_spreadsheet_token',
            'credential_value' => 'eu-brand-source',
        ]);
        $this->assertSame('eu-brand-source', $links->valuesForSync($siblingStore, 'brand')['brand_spreadsheet_token']);
    }

    public function test_attachments_use_authorized_routes_and_reject_unknown_or_cross_store_assets(): void
    {
        [$user, $organization, $store] = $this->context();
        $this->sourceFixture($store);
        app(BrandProfileService::class)->sync($store);
        $profile = app(BrandProfileService::class)->page($store, 'business-licenses');
        $this->assertCount(2, $profile['assets']);
        $this->assertStringNotContainsString('fixture_image_token', json_encode($profile));
        $url = $profile['assets'][0]['url'];
        $this->get($url)->assertRedirect('/login');
        $this->actingAs($user)->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'no-store, private');
        $this->get(str_replace('/'.$store->id.'/', '/999/', $url))->assertNotFound();
        $this->get('/brand-profile/'.$store->id.'/files/'.str_repeat('0', 64))->assertNotFound();
    }

    public function test_failed_refresh_preserves_all_previous_sections_and_changed_source_hides_old_data(): void
    {
        [$user, $organization, $store] = $this->context();
        $this->sourceFixture($store);
        app(BrandProfileService::class)->sync($store);
        $before = FeishuBitableTable::query()->pluck('metadata_encrypted', 'id')->all();
        $brokenClient = \Mockery::mock(FeishuBitableClient::class);
        $brokenClient->shouldReceive('spreadsheetSheets')->andReturn([]);
        try {
            (new BrandProfileService($brokenClient, app(StoreFeishuDataLinkService::class)))->sync($store);
            $this->fail('A missing source worksheet must not replace the previous snapshot.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('缺少', $exception->getMessage());
        }
        $this->assertSame($before, FeishuBitableTable::query()->pluck('metadata_encrypted', 'id')->all());
        StoreBusinessCredential::query()->where('store_id', $store->id)->firstOrFail()->update(['credential_value' => 'new_source_token']);
        $this->assertSame([], app(BrandProfileService::class)->page($store, 'login-emails')['rows']);
    }

    private function sourceFixture(Store $store): void
    {
        StoreBusinessCredential::query()->create([
            'organization_id' => $store->organization_id, 'store_id' => $store->id,
            'provider' => 'feishu_data_links', 'credential_key' => 'brand_spreadsheet_token', 'credential_value' => 'fixture_sheet',
        ]);
        $sheets = [
            'overview' => ['总览', [['项目', '类型', '说明', '链接', '负责人/对接人', '其他'], ['店主', '基础信息', '资料', [['type' => 'url', 'text' => '原表', 'link' => 'https://example.com/reference']], '负责人', null]]],
            'login-emails' => ['登录邮箱', [['账号', '密码', '备注', '备注2'], ['example@example.com', ' fixture-password ', '用途', '备注']]],
            'seo-accounts' => ['SEO账号密码', [['平台', '登陆URL', '账号', '密码'], ['GSC', 'javascript:alert(1)', 'example', '—']]],
            'plugins' => ['插件', [['插件名称', '账号', '密码', '验证', '负责人/对接人', '备注', '收费情况'], ['Example', '', '', '', '', '', 'free']]],
            'business-licenses' => ['营业执照', [[[
                ['type' => 'embed-image', 'fileToken' => 'fixture_image_token', 'text' => '营业执照图片'],
                ['type' => 'attachment', 'fileToken' => 'fixture_pdf_token', 'text' => 'Example.pdf'],
            ]]]],
        ];
        $mock = $this->mock(FeishuBitableClient::class);
        $mock->shouldReceive('spreadsheetSheets')->with('fixture_sheet')->andReturn(collect($sheets)->map(fn ($sheet, $id) => ['sheet_id' => $id, 'title' => $sheet[0], 'grid_properties' => ['row_count' => 20, 'column_count' => 20]])->values()->all());
        foreach ($sheets as $id => $sheet) {
            $mock->shouldReceive('spreadsheetValues')->with('fixture_sheet', $id, 20, 20)->andReturn($sheet[1]);
        }
        $mock->shouldReceive('downloadMedia')->with('fixture_image_token')->andReturn([
            'contents' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aGp8AAAAASUVORK5CYII='),
            'content_type' => 'image/png',
        ]);
    }

    public function test_authorized_users_can_open_each_section_without_exposing_store_settings(): void
    {
        [$user, $organization, $store] = $this->context();
        $store->update(['settings' => ['private_value' => 'never-include-in-profile']]);

        foreach (self::SECTIONS as $section) {
            $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
                ->get('/brand-profile/'.$section)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('BrandProfile/Index')
                    ->where('section', $section)
                    ->where('store.id', $store->id)
                    ->where('store.name', 'Example Brand')
                    ->missing('store.settings'))
                ->assertDontSee('never-include-in-profile');
        }
    }

    public function test_brand_sections_require_login_and_store_view_permission(): void
    {
        foreach (self::SECTIONS as $section) {
            $this->get('/brand-profile/'.$section)->assertRedirect('/login');
        }

        [$user, $organization, $store] = $this->context(false);
        foreach (self::SECTIONS as $section) {
            $this->actingAs($user)
                ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $store->id])
                ->get('/brand-profile/'.$section)
                ->assertForbidden();
        }
    }

    public function test_a_foreign_store_selection_never_exposes_another_organizations_profile(): void
    {
        [$user, $organization, $store] = $this->context();
        $otherOrganization = Organization::query()->create(['name' => 'Other', 'code' => 'other']);
        $otherStore = $otherOrganization->stores()->create(['name' => 'Other Brand', 'shopify_domain' => 'other-brand.myshopify.com', 'status' => 'active']);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id, 'current_store_id' => $otherStore->id])
            ->get('/brand-profile/overview')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('store.id', $store->id)->where('store.name', 'Example Brand'));
    }

    public function test_a_user_without_a_store_gets_an_empty_state_instead_of_an_exception(): void
    {
        [$user, $organization, $store] = $this->context();
        $store->members()->detach($user);

        $this->actingAs($user)
            ->withSession(['current_organization_id' => $organization->id])
            ->get('/brand-profile/overview')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('store', null));

        $this->get('/brand-profile/not-a-section')->assertNotFound();
    }

    /** @return array{User, Organization, Store} */
    private function context(bool $grantView = true): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create();
        $organization = Organization::query()->create(['name' => 'Example', 'code' => 'example']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $organization->stores()->create([
            'name' => 'Example Brand', 'shopify_domain' => 'example-brand.myshopify.com', 'status' => 'active',
            'currency' => 'USD', 'timezone' => 'America/Los_Angeles',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        if ($grantView) {
            $role = Role::query()->create(['organization_id' => $organization->id, 'name' => 'Brand viewer', 'slug' => 'brand-viewer']);
            $role->permissions()->attach(Permission::query()->where('slug', 'store.view')->firstOrFail());
            $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);
        }

        return [$user, $organization, $store];
    }
}
