<?php

namespace App\Services\StudentDiscount;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountCampaign;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class StudentDiscountEmailTemplateService
{
    /** @var array<string, string> */
    private const BRANDING_DEFAULTS = [
        'logo_url' => '',
        'primary_color' => '#111111',
        'shop_url' => '',
        'support_email' => '',
        'support_url' => '',
        'instagram_url' => '',
        'facebook_url' => '',
        'tiktok_url' => '',
        'youtube_url' => '',
    ];

    /** @var array<string, array<string, string>> */
    private const TEMPLATE_DEFAULTS = [
        'approval' => [
            'subject' => 'Your {{ store_name }} student discount code',
            'preheader' => 'Your student discount has been approved.',
            'heading' => 'Your student status is verified! 🎓',
            'body' => 'Use your exclusive student discount code at checkout and save on your next purchase.',
            'cta_label' => 'SHOP NOW',
            'footer_note' => 'Valid on eligible products only. Cannot be combined with other codes.',
        ],
        'rejection' => [
            'subject' => 'Update on your {{ store_name }} student discount request',
            'preheader' => 'There is an update on your student discount request.',
            'heading' => 'We could not verify your student status',
            'body' => "We could not approve your student discount request.\n\nReason: {{ rejection_reason }}",
            'cta_label' => 'VISIT STORE',
            'footer_note' => 'You may submit a new request with a different student ID image.',
        ],
    ];

    /** @var array<string, array{label: string, sample: string, types: list<string>}> */
    private const VARIABLES = [
        'store_name' => ['label' => '店铺名称', 'sample' => 'Example Store', 'types' => ['approval', 'rejection']],
        'applicant_email' => ['label' => '申请邮箱', 'sample' => 'student@example.edu', 'types' => ['approval', 'rejection']],
        'discount_code' => ['label' => '优惠码', 'sample' => 'STUDENT-AB12CD34', 'types' => ['approval']],
        'expires_at' => ['label' => '到期时间', 'sample' => '2026-09-03T12:00:00Z', 'types' => ['approval']],
        'usage_limit' => ['label' => '使用上限', 'sample' => '1', 'types' => ['approval']],
        'rejection_reason' => ['label' => '拒绝原因', 'sample' => 'The submitted image does not look like a student ID.', 'types' => ['rejection']],
    ];

    /** @return array<string, mixed> */
    public function configuration(StudentDiscountCampaign $campaign, Store $store): array
    {
        abort_unless(
            $campaign->organization_id === $store->organization_id && $campaign->store_id === $store->id,
            403,
        );
        $samples = $this->sampleContext(['store_name' => $store->name]);
        $defaults = $this->defaultsForStore($store);

        return [
            ...$this->normalizedConfiguration($campaign->email_templates, $defaults),
            'defaults' => $defaults,
            'variables' => collect(self::VARIABLES)
                ->map(fn (array $details, string $key): array => [
                    'key' => $key,
                    ...$details,
                    'sample' => $samples[$key],
                ])
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $configuration */
    public function update(Organization $organization, Store $store, StudentDiscountCampaign $campaign, array $configuration, User $actor): StudentDiscountCampaign
    {
        $this->assertScope($organization, $store, $campaign);
        $normalized = $this->validateConfiguration($configuration, $this->defaultsForStore($store));
        $before = $this->auditSnapshot($campaign->email_templates);

        $campaign->forceFill([
            'email_templates' => $normalized,
            'updated_by' => $actor->id,
        ])->save();

        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => 'student_discount_email_templates_updated',
            'subject_type' => StudentDiscountCampaign::class,
            'subject_id' => $campaign->id,
            'old_values' => $before,
            'new_values' => $this->auditSnapshot($normalized),
            'metadata' => ['scope' => 'store'],
        ]);

        return $campaign->refresh();
    }

    /**
     * @param  array<string, mixed>|null  $configuration
     * @param  array<string, scalar|null>  $context
     * @return array<string, mixed>
     */
    public function render(string $type, ?array $configuration, array $context): array
    {
        $normalized = $this->normalizedConfiguration($configuration);
        $template = $normalized['templates'][$type] ?? null;
        abort_unless($template !== null, 422);

        $rendered = collect($template)->map(
            fn (string $value): string => $this->replaceVariables($value, $context),
        )->all();
        $branding = $normalized['branding'];
        if ($branding['shop_url'] === '') {
            $branding['shop_url'] = (string) ($context['store_url'] ?? '');
        }

        return [
            'type' => $type,
            ...$rendered,
            'branding' => $branding,
            'store_name' => (string) ($context['store_name'] ?? config('app.name')),
            'discount_code' => (string) ($context['discount_code'] ?? ''),
            'expires_at' => (string) ($context['expires_at'] ?? ''),
            'usage_limit' => (string) ($context['usage_limit'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, scalar|null>  $context
     * @return array<string, mixed>
     */
    public function renderAdHoc(string $type, array $configuration, array $context = []): array
    {
        $validated = $this->validateConfiguration($configuration);

        return $this->render($type, $validated, array_merge($this->sampleContext(), $context));
    }

    /**
     * @param  array<string, scalar|null>  $overrides
     * @return array<string, string>
     */
    public function sampleContext(array $overrides = []): array
    {
        $samples = collect(self::VARIABLES)->mapWithKeys(
            fn (array $details, string $key): array => [$key => $details['sample']],
        )->all();

        foreach ($overrides as $key => $value) {
            if (array_key_exists($key, self::VARIABLES)) {
                $samples[$key] = (string) $value;
            }
        }

        return $samples;
    }

    /** @param array<string, mixed> $configuration @param array<string, mixed>|null $defaults @return array<string, mixed> */
    private function validateConfiguration(array $configuration, ?array $defaults = null): array
    {
        $normalized = $this->normalizedConfiguration($configuration, $defaults);

        foreach (array_keys(self::TEMPLATE_DEFAULTS) as $type) {
            foreach ($normalized['templates'][$type] as $field => $value) {
                $this->assertAllowedVariables($type, $value, "{$type}.{$field}");
            }
        }

        if (! str_contains($normalized['templates']['rejection']['body'], '{{ rejection_reason }}')) {
            throw ValidationException::withMessages(['rejection.body' => '拒绝邮件正文必须包含拒绝原因变量 {{ rejection_reason }}。']);
        }
        $this->assertSafeBranding($normalized['branding']);

        return $normalized;
    }

    /** @param array<string, string> $branding */
    private function assertSafeBranding(array $branding): void
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $branding['primary_color']) !== 1) {
            throw ValidationException::withMessages(['branding.primary_color' => '品牌颜色必须是六位十六进制颜色。']);
        }
        foreach (['logo_url', 'shop_url', 'support_url', 'instagram_url', 'facebook_url', 'tiktok_url', 'youtube_url'] as $field) {
            $value = $branding[$field];
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
                throw ValidationException::withMessages(["branding.{$field}" => '请输入完整的 http 或 https 链接。']);
            }
            if ($value !== '' && ! in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                throw ValidationException::withMessages(["branding.{$field}" => '链接仅支持 http 或 https。']);
            }
        }
        if ($branding['support_email'] !== '' && filter_var($branding['support_email'], FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['branding.support_email' => '客服邮箱格式无效。']);
        }
    }

    private function assertAllowedVariables(string $type, string $content, string $field): void
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $content, $matches);
        $unknown = collect($matches[1] ?? [])->first(function (string $key) use ($type): bool {
            $details = self::VARIABLES[$key] ?? null;

            return $details === null || ! in_array($type, $details['types'], true);
        });
        if ($unknown !== null) {
            throw ValidationException::withMessages([$field => "不支持变量 {{ {$unknown} }}。"]);
        }
        if (preg_match('/\{\{(?!\s*[a-z_]+\s*\}\})/', $content) === 1) {
            throw ValidationException::withMessages([$field => '变量格式无效，请使用页面提供的变量按钮。']);
        }
    }

    /** @param array<string, scalar|null> $context */
    private function replaceVariables(string $content, array $context): string
    {
        $replacements = [];
        foreach (self::VARIABLES as $key => $_details) {
            $replacements["{{ {$key} }}"] = (string) ($context[$key] ?? '');
        }

        return strtr($content, $replacements);
    }

    /** @param array<string, mixed>|null $configuration @param array<string, mixed>|null $defaults @return array<string, mixed> */
    private function normalizedConfiguration(?array $configuration, ?array $defaults = null): array
    {
        $defaults ??= ['branding' => self::BRANDING_DEFAULTS, 'templates' => self::TEMPLATE_DEFAULTS];
        $storedBranding = is_array($configuration['branding'] ?? null) ? $configuration['branding'] : [];
        $branding = collect($defaults['branding'])->mapWithKeys(
            fn (string $value, string $key): array => [$key => trim((string) ($storedBranding[$key] ?? $value))],
        )->all();
        $templates = collect(self::TEMPLATE_DEFAULTS)->mapWithKeys(function (array $baseDefaults, string $type) use ($configuration, $defaults): array {
            $stored = is_array($configuration[$type] ?? null)
                ? $configuration[$type]
                : (is_array($configuration['templates'][$type] ?? null) ? $configuration['templates'][$type] : []);
            $typeDefaults = $defaults['templates'][$type] ?? $baseDefaults;

            return [$type => collect($typeDefaults)->mapWithKeys(
                fn (string $value, string $key): array => [$key => trim((string) ($stored[$key] ?? $value))],
            )->all()];
        })->all();

        return ['branding' => $branding, 'templates' => $templates];
    }

    /** @return array<string, mixed> */
    private function defaultsForStore(Store $store): array
    {
        return [
            'branding' => [
                ...self::BRANDING_DEFAULTS,
                'shop_url' => 'https://'.$store->shopify_domain,
            ],
            'templates' => self::TEMPLATE_DEFAULTS,
        ];
    }

    /** @param array<string, mixed>|null $configuration @return array<string, mixed> */
    private function auditSnapshot(?array $configuration): array
    {
        $normalized = $this->normalizedConfiguration($configuration);

        return [
            'branding' => [
                'primary_color' => $normalized['branding']['primary_color'],
                'logo_configured' => $normalized['branding']['logo_url'] !== '',
                'shop_link_configured' => $normalized['branding']['shop_url'] !== '',
                'support_configured' => $normalized['branding']['support_email'] !== '' || $normalized['branding']['support_url'] !== '',
                'social_link_count' => collect(['instagram_url', 'facebook_url', 'tiktok_url', 'youtube_url'])
                    ->filter(fn (string $field): bool => $normalized['branding'][$field] !== '')
                    ->count(),
            ],
            'templates' => collect($normalized['templates'])->map(fn (array $template): array => [
                'subject' => $template['subject'],
                'content_length' => collect($template)->sum(fn (string $value): int => mb_strlen($value)),
                'content_sha256' => hash('sha256', implode("\n", $template)),
            ])->all(),
        ];
    }

    private function assertScope(Organization $organization, Store $store, StudentDiscountCampaign $campaign): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($campaign->organization_id === $organization->id && $campaign->store_id === $store->id, 403);
    }
}
