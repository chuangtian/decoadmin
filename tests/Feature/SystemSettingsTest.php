<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_individual_settings_pages_and_receive_saved_secrets(): void
    {
        [$user, $organization, $store] = $this->context('super-admin');
        $this->setting('mail', 'password', 'smtp-visible-secret', true, $user);
        $this->setting('feishu', 'app_secret', 'feishu-visible-secret', true, $user);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.settings.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('System/Settings')
                ->where('canUpdate', true)
                ->has('settings.platform_name')
                ->where('timezones', fn ($zones): bool => collect($zones)->contains('UTC')));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.settings.mail'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('System/MailSettings')
                ->where('canUpdate', true)
                ->where('settings.password', 'smtp-visible-secret')
                ->where('settings.password_configured', true));

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.settings.feishu'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('System/FeishuSettings')
                ->where('canUpdate', true)
                ->where('settings.app_secret', 'feishu-visible-secret')
                ->where('settings.app_secret_configured', true));
    }

    public function test_read_only_user_can_view_settings_without_receiving_secrets(): void
    {
        [$user, $organization, $store] = $this->context('viewer');
        $this->setting('mail', 'password', 'smtp-hidden-secret', true, $user);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->get(route('system.settings.mail'))
            ->assertOk()
            ->assertDontSee('smtp-hidden-secret')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canUpdate', false)
                ->where('settings.password', '')
                ->where('settings.password_configured', true));
    }

    public function test_super_admin_can_update_general_and_system_mail_settings(): void
    {
        [$user, $organization, $store] = $this->context('super-admin');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('system.settings.general.update'), [
                'platform_name' => 'DecoAdmin Platform',
                'timezone' => 'America/New_York',
                'locale' => 'zh_CN',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('system.settings.mail.update'), [
                'enabled' => true,
                'host' => 'smtp.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'system@example.com',
                'password' => 'smtp-password-secret',
                'from_address' => 'system@example.com',
                'from_name' => 'DecoAdmin 系统',
                'timeout' => 15,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('DecoAdmin Platform', $this->storedValue('general', 'platform_name'));
        $this->assertSame('America/New_York', $this->storedValue('general', 'timezone'));
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('UTC', date_default_timezone_get());
        $this->assertSame('America/New_York', config('system.display_timezone'));
        $this->assertSame('smtp-password-secret', $this->storedValue('mail', 'password'));
        $this->assertSame('system', config('mail.default'));
        $this->assertSame('smtp-password-secret', config('mail.mailers.system.password'));
        $this->assertSame('system@example.com', config('mail.from.address'));

        $rawPassword = DB::table('system_settings')->where('section', 'mail')->where('key', 'password')->value('value');
        $this->assertIsString($rawPassword);
        $this->assertStringNotContainsString('smtp-password-secret', $rawPassword);
    }

    public function test_settings_with_an_unreadable_old_cipher_do_not_block_other_updates_and_can_be_replaced(): void
    {
        [$user, $organization, $store] = $this->context('super-admin');
        $this->setting('student_ai', 'gemini_api_key', 'current-gemini-secret', true, $user);
        $this->setting('student_ai', 'gemini_model', 'gemini-2.5-pro', false, $user);
        $this->setting('student_ai', 'auto_approval_threshold', 80, false, $user);

        DB::table('system_settings')->insert([
            'section' => 'mail',
            'key' => 'password',
            'value' => 'ciphertext-from-a-different-app-key',
            'is_secret' => true,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = $this->contextSession($organization, $store);

        $this->actingAs($user)
            ->withSession($session)
            ->put(route('system.settings.student-ai.update'), [
                'gemini_api_key' => '',
                'gemini_model' => 'gemini-2.5-pro',
                'auto_approval_threshold' => 82,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($user)
            ->withSession($session)
            ->put(route('system.settings.general.update'), [
                'platform_name' => 'Recovered DecoAdmin',
                'timezone' => 'Asia/Shanghai',
                'locale' => 'zh_CN',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($user)
            ->withSession($session)
            ->get(route('system.settings.mail'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.password', '')
                ->where('settings.password_configured', false));

        $this->actingAs($user)
            ->withSession($session)
            ->put(route('system.settings.mail.update'), [
                'enabled' => true,
                'host' => 'smtp.recovered.example.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'recovered@example.com',
                'password' => 'replacement-mail-secret',
                'from_address' => 'recovered@example.com',
                'from_name' => 'Recovered Mail',
                'timeout' => 10,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('current-gemini-secret', $this->storedValue('student_ai', 'gemini_api_key'));
        $this->assertSame(82, $this->storedValue('student_ai', 'auto_approval_threshold'));
        $this->assertSame('Recovered DecoAdmin', $this->storedValue('general', 'platform_name'));
        $this->assertSame('replacement-mail-secret', $this->storedValue('mail', 'password'));
    }

    public function test_feishu_settings_are_saved_and_audited_without_secret_values(): void
    {
        [$user, $organization, $store] = $this->context('super-admin');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('system.settings.feishu.update'), [
                'enabled' => true,
                'app_id' => 'cli_test_app',
                'app_secret' => 'feishu-app-secret',
                'verification_token' => 'feishu-verification-token',
                'encrypt_key' => 'feishu-encrypt-key',
                'bot_webhook_url' => 'https://open.feishu.cn/open-apis/bot/v2/hook/test-token',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('cli_test_app', $this->storedValue('feishu', 'app_id'));
        $this->assertSame('feishu-app-secret', $this->storedValue('feishu', 'app_secret'));
        $this->assertSame('feishu-app-secret', config('services.feishu.app_secret'));

        $audit = AuditLog::query()->where('action', 'system_settings_updated')->sole();
        $serialized = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
        $this->assertSame('system', data_get($audit->metadata, 'scope'));
        $this->assertSame('feishu', data_get($audit->metadata, 'section'));
        $this->assertStringNotContainsString('feishu-app-secret', $serialized);
        $this->assertStringNotContainsString('feishu-verification-token', $serialized);
        $this->assertStringNotContainsString('feishu-encrypt-key', $serialized);
    }

    public function test_system_mail_settings_do_not_modify_store_mail_settings(): void
    {
        [$user, $organization, $store] = $this->context('super-admin');
        $store->update(['settings' => ['mail' => ['from_address' => 'store@example.com']]]);

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('system.settings.mail.update'), [
                'enabled' => true,
                'host' => 'smtp.system.example.com',
                'port' => 465,
                'encryption' => 'ssl',
                'username' => 'system@example.com',
                'password' => 'system-only-secret',
                'from_address' => 'system@example.com',
                'from_name' => 'System Mail',
                'timeout' => 10,
            ])
            ->assertRedirect();

        $this->assertSame('store@example.com', data_get($store->fresh()->settings, 'mail.from_address'));
        $this->assertSame('system@example.com', $this->storedValue('mail', 'from_address'));
    }

    public function test_user_without_update_permission_cannot_change_system_settings(): void
    {
        [$user, $organization, $store] = $this->context('viewer');

        $this->actingAs($user)
            ->withSession($this->contextSession($organization, $store))
            ->put(route('system.settings.general.update'), [
                'platform_name' => 'Unauthorized',
                'timezone' => 'UTC',
                'locale' => 'zh_CN',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('system_settings', ['section' => 'general', 'key' => 'platform_name']);
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
            'shopify_domain' => 'macfox-settings.myshopify.com',
            'status' => 'active',
        ]);
        $store->members()->attach($user, ['status' => 'active', 'joined_at' => now()]);

        $this->seed(RoleSeeder::class);
        $role = Role::query()->whereBelongsTo($organization)->where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role, ['organization_id' => $organization->id, 'store_id' => null]);

        return [$user, $organization, $store];
    }

    private function setting(string $section, string $key, mixed $value, bool $secret, User $user): SystemSetting
    {
        return SystemSetting::query()->create([
            'section' => $section,
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'is_secret' => $secret,
            'updated_by' => $user->id,
        ]);
    }

    private function storedValue(string $section, string $key): mixed
    {
        $value = SystemSetting::query()->where('section', $section)->where('key', $key)->value('value');

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
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
