<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SystemSettingsService
{
    /** @var array<string, list<string>> */
    private const KEYS = [
        'general' => ['platform_name', 'timezone', 'locale'],
        'mail' => ['enabled', 'host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name', 'timeout'],
        'feishu' => ['enabled', 'app_id', 'app_secret', 'verification_token', 'encrypt_key', 'bot_webhook_url'],
    ];

    /** @var list<string> */
    private const SECRET_KEYS = ['password', 'app_secret', 'verification_token', 'encrypt_key', 'bot_webhook_url'];

    /** @return array<string, mixed> */
    public function sectionForFrontend(string $section, bool $includeSecrets): array
    {
        abort_unless(array_key_exists($section, self::KEYS), 404);

        $settings = $this->section($section);

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

        return $settings;
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
        $before = collect($this->section($section))->only(array_keys($clean))->all();
        $changedKeys = collect($clean)
            ->filter(fn (mixed $value, string $key): bool => $this->normalize($value) !== $this->normalize($before[$key] ?? null))
            ->keys()
            ->values()
            ->all();

        DB::transaction(function () use ($section, $clean, $actor): void {
            foreach ($clean as $key => $value) {
                SystemSetting::query()->updateOrCreate(
                    ['section' => $section, 'key' => $key],
                    [
                        'value' => $this->encode($value),
                        'is_secret' => in_array($key, self::SECRET_KEYS, true),
                        'updated_by' => $actor->id,
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

    /** @return array<string, array<string, mixed>> */
    private function all(): array
    {
        return [
            'general' => $this->section('general'),
            'mail' => $this->section('mail'),
            'feishu' => $this->section('feishu'),
        ];
    }

    /** @return array<string, mixed> */
    private function section(string $section): array
    {
        $values = SystemSetting::query()
            ->where('section', $section)
            ->pluck('value', 'key')
            ->map(fn (?string $value): mixed => $this->decode($value))
            ->all();

        return [...$this->defaults($section), ...$values];
    }

    private function value(string $section, string $key): mixed
    {
        $value = SystemSetting::query()->where('section', $section)->where('key', $key)->value('value');

        return $this->decode($value);
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
            default => [],
        };
    }

    private function encode(mixed $value): string
    {
        return json_encode($this->normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function decode(?string $value): mixed
    {
        return $value === null ? null : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return trim($value);
        }

        return $value;
    }
}
