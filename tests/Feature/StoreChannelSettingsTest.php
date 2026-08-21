<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreBusinessCredential;
use App\Models\StoreNotificationSetting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreChannelSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_settings_pages_use_the_current_authorized_store(): void
    {
        Config::set('services.feishu_table.app_id', 'cli_shared123');
        Config::set('services.feishu_table.app_secret', 'shared-table-secret');
        [$user, $organization, $store] = $this->context('organization-admin');
        $secondStore = $this->addStore($user, $organization, 'EU Store', 'settings-eu.myshopify.com');
        $this->settings($store, ['mail_recipients' => ['us@example.com']]);
        $this->settings($secondStore, [
            'mail_recipients' => ['eu@example.com'],
            'feishu_webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/eu-secret',
        ]);

        $session = $this->contextSession($organization, $secondStore);
        $this->actingAs($user)->withSession($session)->get(route('store-settings.status'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stores/Settings/Status')
                ->where('store.data.id', $secondStore->id)
                ->where('store.data.name', 'EU Store')
                ->where('store.data.shopify_domain', 'settings-eu.myshopify.com'));

        $this->actingAs($user)->withSession($session)->get(route('store-settings.mail'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stores/Settings/Mail')
                ->where('store.id', $secondStore->id)
                ->where('settings.notification_email', 'eu@example.com')
                ->where('canUpdate', true));

        $this->actingAs($user)->withSession($session)->get(route('store-settings.feishu'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stores/Settings/Feishu')
                ->where('store.id', $secondStore->id)
                ->where('settings.feishu_webhook_configured', true)
                ->where('tableSettings.feishu_table_app_id', 'cli_shared123')
                ->where('tableSettings.feishu_table_app_secret', 'shared-table-secret')
                ->where('tableSettings.feishu_table_configured', true));

        $this->actingAs($user)->withSession($session)->get(route('stores.notifications.show', $secondStore))
            ->assertRedirect(route('store-settings.mail'));
    }

    public function test_store_mail_and_feishu_settings_save_separately_and_audit_without_secrets(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)->withSession($session)->put(route('store-settings.mail.update'), [
            'mail_enabled' => true,
            'mail_host' => 'smtp.example.com',
            'mail_port' => 465,
            'mail_encryption' => 'ssl',
            'mail_username' => 'store@example.com',
            'mail_password' => 'store-mail-secret',
            'mail_from_address' => 'store@example.com',
            'mail_from_name' => 'Store Alerts',
            'notification_email' => 'notify@example.com',
        ])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($user)->withSession($session)->put(route('store-settings.feishu.update'), [
            'feishu_enabled' => true,
            'feishu_webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/private-token',
            'feishu_secret' => 'store-feishu-secret',
        ])->assertRedirect()->assertSessionHas('success');

        $setting = StoreNotificationSetting::query()->sole();
        $this->assertSame(['notify@example.com'], $setting->mail_recipients);
        $this->assertSame('store-mail-secret', $setting->mail_password);
        $this->assertSame('store-feishu-secret', $setting->feishu_secret);

        $raw = DB::table('store_notification_settings')->where('id', $setting->id)->first();
        $this->assertStringNotContainsString('store-mail-secret', (string) $raw->mail_password);
        $this->assertStringNotContainsString('private-token', (string) $raw->feishu_webhook_url);

        $audits = DB::table('audit_logs')->whereIn('action', [
            'store_mail_settings_updated',
            'store_feishu_settings_updated',
        ])->get();
        $this->assertCount(2, $audits);
        $this->assertStringNotContainsString('store-mail-secret', $audits->toJson());
        $this->assertStringNotContainsString('store-feishu-secret', $audits->toJson());
        $this->assertStringNotContainsString('private-token', $audits->toJson());
    }

    public function test_viewer_can_view_but_cannot_change_store_channel_settings_or_receive_secrets(): void
    {
        Config::set('services.feishu_table.app_id', 'cli_shared123');
        Config::set('services.feishu_table.app_secret', 'shared-table-secret');
        [$user, $organization, $store] = $this->context('viewer');
        $this->settings($store, [
            'mail_password' => 'hidden-mail-secret',
            'feishu_webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/hidden-token',
        ]);
        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)->withSession($session)->get(route('store-settings.mail'))
            ->assertOk()
            ->assertDontSee('hidden-mail-secret')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canUpdate', false)
                ->where('settings.mail_password', '')
                ->where('settings.mail_password_configured', true));

        $this->actingAs($user)->withSession($session)->put(route('store-settings.feishu.update'), [
            'feishu_enabled' => false,
            'feishu_webhook_url' => '',
            'feishu_secret' => '',
        ])->assertForbidden();

        $this->actingAs($user)->withSession($session)->get(route('store-settings.feishu'))
            ->assertOk()
            ->assertDontSee('shared-table-secret')
            ->assertInertia(fn (Assert $page) => $page
                ->where('tableSettings.feishu_table_app_id', 'cli_shared123')
                ->where('tableSettings.feishu_table_app_secret', '')
                ->where('tableSettings.feishu_table_app_secret_configured', true));

        $this->actingAs($user)->withSession($session)->patch(route('store-settings.feishu.toggle'), [
            'enabled' => true,
        ])->assertForbidden();
    }

    public function test_mail_and_feishu_enable_switches_save_immediately(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $this->settings($store, [
            'mail_enabled' => false,
            'mail_host' => 'smtp.example.com',
            'mail_password' => 'mail-secret',
            'mail_from_address' => 'store@example.com',
            'mail_recipients' => ['notify@example.com'],
            'feishu_enabled' => false,
            'feishu_webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/private-token',
        ]);
        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)->withSession($session)->patch(route('store-settings.mail.toggle'), [
            'enabled' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($user)->withSession($session)->patch(route('store-settings.feishu.toggle'), [
            'enabled' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $setting = $store->notificationSetting()->firstOrFail();
        $this->assertTrue($setting->mail_enabled);
        $this->assertTrue($setting->feishu_enabled);
        $this->assertDatabaseHas('audit_logs', ['action' => 'store_mail_channel_toggled']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'store_feishu_channel_toggled']);
    }

    public function test_feishu_data_links_are_saved_only_for_the_current_store_and_encrypted(): void
    {
        [$user, $organization, $store] = $this->context('organization-admin');
        $secondStore = $this->addStore($user, $organization, 'EU Store', 'settings-eu.myshopify.com');
        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $secondStore->id,
            'provider' => 'feishu_data_links',
            'credential_key' => 'brand_spreadsheet_token',
            'credential_value' => 'second-store-secret',
            'updated_by' => $user->id,
        ]);
        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)->withSession($session)->put(
            route('store-settings.feishu.data-links.update', ['section' => 'brand']),
            ['values' => [
                'brand_spreadsheet_token' => 'current-store-secret',
                'brand_license_sheet_id' => 'sheet-current',
                'brand_wiki_url' => 'https://example.feishu.cn/wiki/current',
            ]],
        )->assertRedirect()->assertSessionHas('success');

        $credential = StoreBusinessCredential::query()
            ->whereBelongsTo($store)
            ->where('provider', 'feishu_data_links')
            ->where('credential_key', 'brand_spreadsheet_token')
            ->sole();

        $this->assertSame('current-store-secret', $credential->credential_value);
        $this->assertSame(
            'second-store-secret',
            StoreBusinessCredential::query()
                ->whereBelongsTo($secondStore)
                ->where('provider', 'feishu_data_links')
                ->where('credential_key', 'brand_spreadsheet_token')
                ->sole()
                ->credential_value,
        );
        $this->assertStringNotContainsString(
            'current-store-secret',
            (string) DB::table('store_business_credentials')->where('id', $credential->id)->value('credential_value'),
        );

        $this->actingAs($user)->withSession($session)->get(
            route('store-settings.feishu.data-links.reveal', [
                'section' => 'brand',
                'field' => 'brand_spreadsheet_token',
            ]),
        )->assertOk()->assertJsonPath('data.value', 'current-store-secret');

        $this->actingAs($user)->withSession($session)->get(route('store-settings.feishu'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('dataLinks.0.key', 'brand')
                ->where('dataLinks.0.fields.0.configured', true)
                ->where('dataLinks.0.fields.0.current_value', '')
                ->where('dataLinks.0.fields.1.current_value', 'sheet-current'));

        $audit = DB::table('audit_logs')->where('action', 'store_feishu_data_links_updated')->sole();
        $this->assertSame($store->id, $audit->store_id);
        $this->assertStringNotContainsString('current-store-secret', json_encode($audit));
    }

    public function test_viewer_cannot_change_or_reveal_feishu_data_links(): void
    {
        [$user, $organization, $store] = $this->context('viewer');
        StoreBusinessCredential::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'provider' => 'feishu_data_links',
            'credential_key' => 'brand_spreadsheet_token',
            'credential_value' => 'viewer-hidden-secret',
            'updated_by' => $user->id,
        ]);
        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)->withSession($session)->get(route('store-settings.feishu'))
            ->assertOk()
            ->assertDontSee('viewer-hidden-secret')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canUpdate', false)
                ->where('dataLinks.0.fields.0.configured', true)
                ->where('dataLinks.0.fields.0.masked_value', '')
                ->where('dataLinks.0.fields.0.current_value', ''));

        $this->actingAs($user)->withSession($session)->put(
            route('store-settings.feishu.data-links.update', ['section' => 'brand']),
            ['values' => ['brand_spreadsheet_token' => 'forbidden-change']],
        )->assertForbidden();

        $this->actingAs($user)->withSession($session)->get(
            route('store-settings.feishu.data-links.reveal', [
                'section' => 'brand',
                'field' => 'brand_spreadsheet_token',
            ]),
        )->assertForbidden();
    }

    private function context(string $roleSlug): array
    {
        $this->seed(PermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $organization = Organization::query()->create(['name' => 'Deco', 'code' => 'deco-settings']);
        $organization->users()->attach($user, ['status' => 'active', 'joined_at' => now()]);
        $store = $this->addStore($user, $organization, 'US Store', 'settings-us.myshopify.com');
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

    private function settings(Store $store, array $overrides): StoreNotificationSetting
    {
        return StoreNotificationSetting::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'mail_enabled' => false,
            'mail_port' => 587,
            'mail_encryption' => 'tls',
            'mail_recipients' => [],
            'feishu_enabled' => false,
            ...$overrides,
        ]);
    }

    private function contextSession(Organization $organization, Store $store): array
    {
        return [
            'current_organization_id' => $organization->id,
            'current_store_id' => $store->id,
        ];
    }
}
