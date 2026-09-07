<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use JsonException;

class SystemSettingsService
{
    /** @var array<string, list<string>> */
    private const KEYS = [
        'general' => ['platform_name', 'timezone', 'locale'],
        'mail' => ['enabled', 'host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name', 'timeout'],
        'feishu' => ['enabled', 'app_id', 'app_secret', 'verification_token', 'encrypt_key', 'bot_webhook_url'],
        'student_ai' => ['gemini_api_key', 'gemini_model', 'auto_approval_threshold'],
        'instagram_meta' => ['instagram_app_id', 'instagram_app_secret', 'facebook_app_id', 'facebook_app_secret', 'facebook_login_config_id'],
        'instagram_r2' => ['account_id', 'access_key_id', 'secret_access_key', 'bucket', 'public_base_url'],
    ];

    /** @var list<string> */
    private const SECRET_KEYS = [
        'password', 'app_secret', 'verification_token', 'encrypt_key', 'bot_webhook_url', 'gemini_api_key',
        'instagram_app_secret', 'facebook_app_secret', 'secret_access_key',
    ];

    /**
     * 只写密钥：永不回显给前端（即使调用方有更新权限），
     * 提交空值表示保持原值不变，只能整体覆盖、不能读取。
     *
     * @var list<string>
     */
    private const WRITE_ONLY_KEYS = ['gemini_api_key', 'instagram_app_secret', 'facebook_app_secret', 'secret_access_key'];

    /** @return array<string, mixed> */
    public function sectionForFrontend(string $section, bool $includeSecrets): array
    {
        abort_unless(array_key_exists($section, self::KEYS), 404);

        $settings = $this->section($section);
        // 生效值可能来自数据库，也可能来自 .env 兜底；配置状态必须在脱敏之前算。
        $writeOnlyConfigured = collect(self::KEYS[$section])
            ->filter(fn (string $key): bool => in_array($key, self::WRITE_ONLY_KEYS, true))
            ->mapWithKeys(fn (string $key): array => [$key.'_configured' => filled($settings[$key] ?? null)])
            ->all();

        if (! $includeSecrets) {
            foreach (self::SECRET_KEYS as $key) {
                if (array_key_exists($key, $settings)) {
                    $settings[$key] = '';
                }
            }
        }

        if ($section === 'mail') {
            $settings['password_configured'] = filled($this->value('mail', 'password'));
        }

        if ($section === 'feishu') {
            $settings['app_secret_configured'] = filled($this->value('feishu', 'app_secret'));
            $settings['verification_token_configured'] = filled($this->value('feishu', 'verification_token'));
            $settings['encrypt_key_configured'] = filled($this->value('feishu', 'encrypt_key'));
            $settings['bot_webhook_configured'] = filled($this->value('feishu', 'bot_webhook_url'));
        }

        foreach (self::KEYS[$section] as $key) {
            if (in_array($key, self::WRITE_ONLY_KEYS, true)) {
                $settings[$key] = '';
            }
        }

        return [...$settings, ...$writeOnlyConfigured];
    }

    /** @return array{gemini_api_key: string, gemini_model: string, auto_approval_threshold: float} */
    public function studentAiForServer(): array
    {
        $settings = $this->section('student_ai');

        return [
            'gemini_api_key' => (string) $settings['gemini_api_key'],
            'gemini_model' => (string) $settings['gemini_model'],
            'auto_approval_threshold' => (float) $settings['auto_approval_threshold'],
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function update(
        string $section,
        array $values,
        User $actor,
        Organization $organization,
        ?Store $store,
    ): array {
        abort_unless(array_key_exists($section, self::KEYS), 422, '不支持的系统设置分组。');

        $allowed = collect(self::KEYS[$section]);
        $clean = collect($values)->only($allowed)->all();
        // 只写密钥留空表示保持原值，避免前端不回显时把已存的密钥清空。
        foreach (self::WRITE_ONLY_KEYS as $key) {
            if (array_key_exists($key, $clean) && blank($clean[$key])) {
                unset($clean[$key]);
            }
        }
        $before = collect($this->section($section))->only(array_keys($clean))->all();
        $changedKeys = collect($clean)
            ->filter(fn (mixed $value, string $key): bool => $this->normalize($value) !== $this->normalize($before[$key] ?? null))
            ->keys()
            ->values()
            ->all();

        DB::transaction(function () use ($section, $clean, $actor): void {
            foreach ($clean as $key => $value) {
                DB::table('system_settings')->updateOrInsert(
                    ['section' => $section, 'key' => $key],
                    fn (bool $exists): array => [
                        'value' => Crypt::encryptString($this->encode($value)),
                        'is_secret' => in_array($key, self::SECRET_KEYS, true),
                        'updated_by' => $actor->id,
                        'updated_at' => now(),
                        ...($exists ? [] : ['created_at' => now()]),
                    ],
                );
            }
        });

        if ($changedKeys !== []) {
            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store?->id,
                'user_id' => $actor->id,
                'action' => 'system_settings_updated',
                'metadata' => [
                    'scope' => 'system',
                    'section' => $section,
                    'changed_keys' => $changedKeys,
                ],
            ]);
        }

        $this->applyRuntimeConfiguration();

        return $changedKeys;
    }

    public function applyRuntimeConfiguration(): void
    {
        $settings = $this->all();
        $general = $settings['general'];

        config([
            'app.name' => $general['platform_name'],
            // Persistence and background jobs always run in UTC. The configured
            // timezone is a display fallback only; store-facing dates use the
            // Shopify shop's own IANA timezone.
            'app.timezone' => 'UTC',
            'system.display_timezone' => $general['timezone'],
            'app.locale' => $general['locale'],
            'services.feishu' => $settings['feishu'],
        ]);
        date_default_timezone_set('UTC');
        app()->setLocale((string) $general['locale']);
        $this->applyInstagramFeedConfiguration($settings['instagram_meta'], $settings['instagram_r2']);

        if (! $settings['mail']['enabled']) {
            return;
        }

        $mail = $settings['mail'];
        config([
            'mail.default' => 'system',
            'mail.mailers.system' => [
                'transport' => 'smtp',
                'scheme' => $mail['encryption'] === 'ssl' ? 'smtps' : null,
                'host' => $mail['host'],
                'port' => (int) $mail['port'],
                'username' => $mail['username'] ?: null,
                'password' => $mail['password'] ?: null,
                'timeout' => (int) $mail['timeout'],
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST),
            ],
            'mail.from.address' => $mail['from_address'],
            'mail.from.name' => $mail['from_name'],
        ]);
        Mail::purge('system');
    }

    /**
     * Instagram Feed 的 Meta 应用凭证和 R2 存储凭证在后台维护，
     * 数据库有值就覆盖 config，没值时保持 .env 兜底。
     * R2Client / InstagramApiClient / FacebookApiClient 都是调用时才读 config，
     * 所以这里覆盖后无需改动它们。
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $r2
     */
    private function applyInstagramFeedConfiguration(array $meta, array $r2): void
    {
        config([
            'instagram_feed.instagram.app_id' => trim((string) $meta['instagram_app_id']),
            'instagram_feed.instagram.app_secret' => (string) $meta['instagram_app_secret'],
            'instagram_feed.facebook.app_id' => trim((string) $meta['facebook_app_id']),
            'instagram_feed.facebook.app_secret' => (string) $meta['facebook_app_secret'],
            'instagram_feed.facebook.login_config_id' => trim((string) $meta['facebook_login_config_id']) ?: null,
            'instagram_feed.r2.account_id' => trim((string) $r2['account_id']),
            'instagram_feed.r2.access_key_id' => trim((string) $r2['access_key_id']),
            'instagram_feed.r2.secret_access_key' => (string) $r2['secret_access_key'],
            'instagram_feed.r2.bucket' => trim((string) $r2['bucket']),
            'instagram_feed.r2.public_base_url' => rtrim(trim((string) $r2['public_base_url']), '/'),
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function all(): array
    {
        return [
            'general' => $this->section('general'),
            'mail' => $this->section('mail'),
            'feishu' => $this->section('feishu'),
            'student_ai' => $this->section('student_ai'),
            'instagram_meta' => $this->section('instagram_meta'),
            'instagram_r2' => $this->section('instagram_r2'),
        ];
    }

    /** @return array<string, mixed> */
    private function section(string $section): array
    {
        $values = DB::table('system_settings')
            ->where('section', $section)
            ->get(['id', 'key', 'value'])
            ->mapWithKeys(function (object $setting) use ($section): array {
                $decoded = $this->decodePersistedValue(
                    $setting->value,
                    (int) $setting->id,
                    $section,
                    (string) $setting->key,
                );

                return $decoded['valid'] ? [(string) $setting->key => $decoded['value']] : [];
            })
            ->all();

        return [...$this->defaults($section), ...$values];
    }

    private function value(string $section, string $key): mixed
    {
        $setting = DB::table('system_settings')
            ->where('section', $section)
            ->where('key', $key)
            ->first(['id', 'value']);

        if ($setting === null) {
            return null;
        }

        $decoded = $this->decodePersistedValue(
            $setting->value,
            (int) $setting->id,
            $section,
            $key,
        );

        return $decoded['valid'] ? $decoded['value'] : null;
    }

    /** @return array<string, mixed> */
    private function defaults(string $section): array
    {
        return match ($section) {
            'general' => [
                'platform_name' => (string) config('app.name', 'DecoAdmin'),
                'timezone' => (string) config('app.timezone', 'UTC'),
                'locale' => (string) config('app.locale', 'zh_CN'),
            ],
            'mail' => [
                'enabled' => false,
                'host' => (string) config('mail.mailers.smtp.host', ''),
                'port' => (int) config('mail.mailers.smtp.port', 587),
                'encryption' => config('mail.mailers.smtp.scheme') === 'smtps' ? 'ssl' : 'tls',
                'username' => (string) config('mail.mailers.smtp.username', ''),
                'password' => '',
                'from_address' => (string) config('mail.from.address', ''),
                'from_name' => (string) config('mail.from.name', ''),
                'timeout' => 10,
            ],
            'feishu' => [
                'enabled' => false,
                'app_id' => '',
                'app_secret' => '',
                'verification_token' => '',
                'encrypt_key' => '',
                'bot_webhook_url' => '',
            ],
            'student_ai' => [
                'gemini_api_key' => '',
                'gemini_model' => 'gemini-2.5-pro',
                'auto_approval_threshold' => 80,
            ],
            // 未在后台配置时回退到 .env，保证迁移期间已有部署不中断。
            'instagram_meta' => [
                'instagram_app_id' => (string) config('instagram_feed.instagram.app_id', ''),
                'instagram_app_secret' => (string) config('instagram_feed.instagram.app_secret', ''),
                'facebook_app_id' => (string) config('instagram_feed.facebook.app_id', ''),
                'facebook_app_secret' => (string) config('instagram_feed.facebook.app_secret', ''),
                'facebook_login_config_id' => (string) config('instagram_feed.facebook.login_config_id', ''),
            ],
            'instagram_r2' => [
                'account_id' => (string) config('instagram_feed.r2.account_id', ''),
                'access_key_id' => (string) config('instagram_feed.r2.access_key_id', ''),
                'secret_access_key' => (string) config('instagram_feed.r2.secret_access_key', ''),
                'bucket' => (string) config('instagram_feed.r2.bucket', ''),
                'public_base_url' => (string) config('instagram_feed.r2.public_base_url', ''),
            ],
            default => [],
        };
    }

    private function encode(mixed $value): string
    {
        return json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{valid: bool, value: mixed} */
    private function decodePersistedValue(?string $value, int $id, string $section, string $key): array
    {
        if ($value === null || $value === '') {
            return ['valid' => false, 'value' => null];
        }

        try {
            return [
                'valid' => true,
                'value' => json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR),
            ];
        } catch (DecryptException|JsonException $exception) {
            Log::warning('System setting could not be read with the current application key and was ignored.', [
                'setting_id' => $id,
                'section' => $section,
                'key' => $key,
                'reason' => $exception instanceof DecryptException ? 'decrypt_failed' : 'invalid_json',
            ]);

            return ['valid' => false, 'value' => null];
        }
    }

    private function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }
}
