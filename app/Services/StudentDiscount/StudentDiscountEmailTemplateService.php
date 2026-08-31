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
    private const BLOCK_TYPES = ['heading', 'paragraph', 'button', 'note', 'divider', 'spacer', 'discount_code'];

    private const BLOCK_ALIGNMENTS = ['left', 'center', 'right'];

    private const BLOCK_WIDTHS = ['auto', 'full'];

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

    public function supportPageUrl(StudentDiscountCampaign $campaign, Store $store): ?string
    {
        abort_unless(
            $campaign->organization_id === $store->organization_id && $campaign->store_id === $store->id,
            403,
        );

        $url = trim((string) data_get(
            $this->normalizedConfiguration($campaign->email_templates),
            'branding.support_url',
            '',
        ));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
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

        $rendered = $template;
        foreach (['subject', 'preheader', 'heading', 'body', 'cta_label', 'footer_note'] as $field) {
            $rendered[$field] = $this->replaceVariables((string) ($template[$field] ?? ''), $context);
        }
        $rendered['content_blocks'] = collect($template['content_blocks'] ?? [])
            ->map(function (array $block) use ($context): array {
                if (array_key_exists('text', $block)) {
                    $block['text'] = $this->replaceVariables((string) $block['text'], $context);
                }

                return $block;
            })
            ->values()
            ->all();
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
            $template = $normalized['templates'][$type];
            foreach (['subject', 'preheader', 'heading', 'body', 'cta_label', 'footer_note'] as $field) {
                $this->assertAllowedVariables($type, (string) ($template[$field] ?? ''), "{$type}.{$field}");
            }
            foreach ($template['content_blocks'] as $index => $block) {
                $this->assertAllowedVariables($type, (string) ($block['text'] ?? ''), "{$type}.content_blocks.{$index}.text");
                if (($block['type'] ?? '') === 'button') {
                    $this->assertSafeUrl((string) ($block['url'] ?? ''), "{$type}.content_blocks.{$index}.url");
                }
            }
        }

        $rejectionText = collect($normalized['templates']['rejection']['content_blocks'])
            ->pluck('text')
            ->implode("\n");
        if (! str_contains($rejectionText, '{{ rejection_reason }}')) {
            throw ValidationException::withMessages(['rejection.body' => '拒绝邮件内容必须包含拒绝原因变量 {{ rejection_reason }}。']);
        }
        $approvalCodeBlocks = collect($normalized['templates']['approval']['content_blocks'])->where('type', 'discount_code')->count();
        if ($approvalCodeBlocks !== 1) {
            throw ValidationException::withMessages(['approval.content_blocks' => '批准邮件必须保留一个系统优惠码区块。']);
        }
        if (collect($normalized['templates']['rejection']['content_blocks'])->contains('type', 'discount_code')) {
            throw ValidationException::withMessages(['rejection.content_blocks' => '拒绝邮件不能包含优惠码区块。']);
        }
        foreach (['approval', 'rejection'] as $type) {
            if (collect($normalized['templates'][$type]['content_blocks'])->where('type', 'button')->count() > 3) {
                throw ValidationException::withMessages(["{$type}.content_blocks" => '每封邮件最多可插入三个按钮。']);
            }
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
            $this->assertSafeUrl($branding[$field], "branding.{$field}");
        }
        if ($branding['support_email'] !== '' && filter_var($branding['support_email'], FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessages(['branding.support_email' => '客服邮箱格式无效。']);
        }
    }

    private function assertSafeUrl(string $value, string $field): void
    {
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages([$field => '请输入完整的 http 或 https 链接。']);
        }
        if ($value !== '' && ! in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw ValidationException::withMessages([$field => '链接仅支持 http 或 https。']);
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
        $defaults ??= ['branding' => self::BRANDING_DEFAULTS, 'templates' => $this->templateDefaults()];
        $storedBranding = is_array($configuration['branding'] ?? null) ? $configuration['branding'] : [];
        $branding = collect($defaults['branding'])->mapWithKeys(
            fn (string $value, string $key): array => [$key => trim((string) ($storedBranding[$key] ?? $value))],
        )->all();
        $templates = collect(self::TEMPLATE_DEFAULTS)->mapWithKeys(function (array $baseDefaults, string $type) use ($branding, $configuration, $defaults): array {
            $stored = is_array($configuration[$type] ?? null)
                ? $configuration[$type]
                : (is_array($configuration['templates'][$type] ?? null) ? $configuration['templates'][$type] : []);
            $typeDefaults = is_array($defaults['templates'][$type] ?? null) ? $defaults['templates'][$type] : $baseDefaults;
            $legacy = collect($baseDefaults)->mapWithKeys(
                fn (string $value, string $key): array => [$key => trim((string) ($stored[$key] ?? $typeDefaults[$key] ?? $value))],
            )->all();
            $blockSource = is_array($stored['content_blocks'] ?? null)
                ? $stored['content_blocks']
                : $this->legacyContentBlocks($type, $legacy, $branding['primary_color']);

            return [$type => [
                ...$legacy,
                'content_blocks' => $this->normalizedContentBlocks($type, $blockSource, $legacy, $branding['primary_color']),
            ]];
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
            'templates' => $this->templateDefaults(),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function templateDefaults(): array
    {
        return collect(self::TEMPLATE_DEFAULTS)->mapWithKeys(fn (array $template, string $type): array => [
            $type => [
                ...$template,
                'content_blocks' => $this->legacyContentBlocks($type, $template, self::BRANDING_DEFAULTS['primary_color']),
            ],
        ])->all();
    }

    /** @param array<string, string> $legacy @return list<array<string, mixed>> */
    private function legacyContentBlocks(string $type, array $legacy, string $primaryColor): array
    {
        $blocks = [
            $this->textBlock('heading', $legacy['heading'] ?? '', 32, true, false, '#111111'),
            $this->textBlock('paragraph', $legacy['body'] ?? '', 17, false, false, '#404040'),
        ];
        if ($type === 'approval') {
            $blocks[] = ['type' => 'discount_code'];
        }
        if (($legacy['cta_label'] ?? '') !== '') {
            $blocks[] = [
                'type' => 'button',
                'text' => $legacy['cta_label'],
                'url' => '',
                'align' => 'center',
                'font_size' => 18,
                'bold' => true,
                'italic' => false,
                'underline' => false,
                'color' => '#FFFFFF',
                'background_color' => $primaryColor,
                'width' => 'full',
            ];
        }
        if (($legacy['footer_note'] ?? '') !== '') {
            $blocks[] = $this->textBlock('note', $legacy['footer_note'], 14, false, true, '#5F5F5F');
        }

        return $blocks;
    }

    /** @return array<string, mixed> */
    private function textBlock(string $type, string $text, int $fontSize, bool $bold, bool $italic, string $color): array
    {
        return [
            'type' => $type,
            'text' => $text,
            'align' => 'left',
            'font_size' => $fontSize,
            'bold' => $bold,
            'italic' => $italic,
            'underline' => false,
            'color' => $color,
        ];
    }

    /** @param array<string, string> $legacy @return list<array<string, mixed>> */
    private function normalizedContentBlocks(string $type, mixed $blocks, array $legacy, string $primaryColor): array
    {
        if (! is_array($blocks) || $blocks === []) {
            $blocks = $this->legacyContentBlocks($type, $legacy, $primaryColor);
        }

        return collect($blocks)
            ->filter(fn (mixed $block): bool => is_array($block) && in_array((string) ($block['type'] ?? ''), self::BLOCK_TYPES, true))
            ->take(20)
            ->map(function (array $block) use ($primaryColor): array {
                $type = (string) $block['type'];
                if ($type === 'discount_code') {
                    return ['type' => 'discount_code'];
                }
                if ($type === 'divider') {
                    return [
                        'type' => 'divider',
                        'color' => $this->normalizedColor($block['color'] ?? '#E5E7EB', '#E5E7EB'),
                        'spacing' => min(64, max(8, (int) ($block['spacing'] ?? 20))),
                    ];
                }
                if ($type === 'spacer') {
                    return ['type' => 'spacer', 'spacing' => min(64, max(8, (int) ($block['spacing'] ?? 20)))];
                }

                $defaults = match ($type) {
                    'heading' => [32, true, false, '#111111'],
                    'note' => [14, false, true, '#5F5F5F'],
                    'button' => [18, true, false, '#FFFFFF'],
                    default => [17, false, false, '#404040'],
                };
                $normalized = [
                    'type' => $type,
                    'text' => trim((string) ($block['text'] ?? '')),
                    'align' => in_array((string) ($block['align'] ?? ''), self::BLOCK_ALIGNMENTS, true) ? (string) $block['align'] : 'left',
                    'font_size' => min(48, max(10, (int) ($block['font_size'] ?? $defaults[0]))),
                    'bold' => filter_var($block['bold'] ?? $defaults[1], FILTER_VALIDATE_BOOL),
                    'italic' => filter_var($block['italic'] ?? $defaults[2], FILTER_VALIDATE_BOOL),
                    'underline' => filter_var($block['underline'] ?? false, FILTER_VALIDATE_BOOL),
                    'color' => $this->normalizedColor($block['color'] ?? $defaults[3], $defaults[3]),
                ];
                if ($type === 'button') {
                    $normalized['url'] = trim((string) ($block['url'] ?? ''));
                    $normalized['background_color'] = $this->normalizedColor($block['background_color'] ?? $primaryColor, $primaryColor);
                    $normalized['width'] = in_array((string) ($block['width'] ?? ''), self::BLOCK_WIDTHS, true) ? (string) $block['width'] : 'full';
                    $normalized['align'] = 'center';
                }

                return $normalized;
            })
            ->values()
            ->all();
    }

    private function normalizedColor(mixed $value, string $fallback): string
    {
        $color = strtoupper(trim((string) $value));

        return preg_match('/^#[0-9A-F]{6}$/', $color) === 1 ? $color : strtoupper($fallback);
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
                'content_length' => $this->contentLength($template),
                'content_sha256' => hash('sha256', json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: ''),
                'block_count' => count($template['content_blocks'] ?? []),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $content */
    private function contentLength(array $content): int
    {
        return collect($content)->sum(function (mixed $value): int {
            if (is_string($value)) {
                return mb_strlen($value);
            }
            if (is_array($value)) {
                return $this->contentLength($value);
            }

            return 0;
        });
    }

    private function assertScope(Organization $organization, Store $store, StudentDiscountCampaign $campaign): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($campaign->organization_id === $organization->id && $campaign->store_id === $store->id, 403);
    }
}
