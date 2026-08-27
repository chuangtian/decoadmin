<?php

namespace App\Services\StudentDiscount;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountCampaign;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class StudentDiscountEmailTemplateService
{
    /** @var array<string, array{subject: string, body: string}> */
    private const DEFAULTS = [
        'approval' => [
            'subject' => 'Your {{ store_name }} student discount code',
            'body' => "Your student discount request has been approved.\n\nYour discount code: {{ discount_code }}\nExpires: {{ expires_at }}\nUsage limit: {{ usage_limit }}\n\nEnter this code at checkout to apply your student discount.",
        ],
        'rejection' => [
            'subject' => 'Update on your {{ store_name }} student discount request',
            'body' => "We could not approve your student discount request.\n\nReason: {{ rejection_reason }}\n\nYou may submit a new request with a different student ID image.",
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

    /** @return array{templates: array<string, array{subject: string, body: string}>, defaults: array<string, array{subject: string, body: string}>, variables: list<array{key: string, label: string, sample: string, types: list<string>}>} */
    public function configuration(StudentDiscountCampaign $campaign, Store $store): array
    {
        abort_unless(
            $campaign->organization_id === $store->organization_id && $campaign->store_id === $store->id,
            403,
        );
        $samples = $this->sampleContext(['store_name' => $store->name]);

        return [
            'templates' => $this->normalizedTemplates($campaign->email_templates),
            'defaults' => self::DEFAULTS,
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

    /** @param array<string, mixed> $templates */
    public function update(Organization $organization, Store $store, StudentDiscountCampaign $campaign, array $templates, User $actor): StudentDiscountCampaign
    {
        $this->assertScope($organization, $store, $campaign);
        $normalized = $this->validateTemplates($templates);
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
     * @param  array<string, mixed>|null  $templates
     * @param  array<string, scalar|null>  $context
     * @return array{subject: string, body: string}
     */
    public function render(string $type, ?array $templates, array $context): array
    {
        $template = $this->normalizedTemplates($templates)[$type] ?? null;
        abort_unless($template !== null, 422);

        return [
            'subject' => $this->replaceVariables($template['subject'], $context),
            'body' => $this->replaceVariables($template['body'], $context),
        ];
    }

    /**
     * @param  array<string, mixed>  $template
     * @param  array<string, scalar|null>  $context
     * @return array{subject: string, body: string}
     */
    public function renderAdHoc(string $type, array $template, array $context = []): array
    {
        $validated = $this->validateTemplates([
            'approval' => $type === 'approval' ? $template : self::DEFAULTS['approval'],
            'rejection' => $type === 'rejection' ? $template : self::DEFAULTS['rejection'],
        ]);

        return $this->render($type, $validated, $this->sampleContext($context));
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

    /** @param array<string, mixed> $templates @return array<string, array{subject: string, body: string}> */
    private function validateTemplates(array $templates): array
    {
        $normalized = [];
        foreach (array_keys(self::DEFAULTS) as $type) {
            $subject = trim((string) Arr::get($templates, "{$type}.subject", ''));
            $body = trim((string) Arr::get($templates, "{$type}.body", ''));
            $this->assertAllowedVariables($type, $subject, "{$type}.subject");
            $this->assertAllowedVariables($type, $body, "{$type}.body");
            $normalized[$type] = ['subject' => $subject, 'body' => $body];
        }

        if (! str_contains($normalized['approval']['body'], '{{ discount_code }}')) {
            throw ValidationException::withMessages(['approval.body' => '批准邮件正文必须包含优惠码变量 {{ discount_code }}。']);
        }
        if (! str_contains($normalized['rejection']['body'], '{{ rejection_reason }}')) {
            throw ValidationException::withMessages(['rejection.body' => '拒绝邮件正文必须包含拒绝原因变量 {{ rejection_reason }}。']);
        }

        return $normalized;
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

    /** @param array<string, mixed>|null $templates @return array<string, array{subject: string, body: string}> */
    private function normalizedTemplates(?array $templates): array
    {
        return collect(self::DEFAULTS)->mapWithKeys(function (array $defaults, string $type) use ($templates): array {
            $stored = is_array($templates[$type] ?? null) ? $templates[$type] : [];

            return [$type => [
                'subject' => filled($stored['subject'] ?? null) ? (string) $stored['subject'] : $defaults['subject'],
                'body' => filled($stored['body'] ?? null) ? (string) $stored['body'] : $defaults['body'],
            ]];
        })->all();
    }

    /** @param array<string, mixed>|null $templates @return array<string, array{subject: string, body_length: int, body_sha256: string}> */
    private function auditSnapshot(?array $templates): array
    {
        return collect($this->normalizedTemplates($templates))->map(fn (array $template): array => [
            'subject' => $template['subject'],
            'body_length' => mb_strlen($template['body']),
            'body_sha256' => hash('sha256', $template['body']),
        ])->all();
    }

    private function assertScope(Organization $organization, Store $store, StudentDiscountCampaign $campaign): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($campaign->organization_id === $organization->id && $campaign->store_id === $store->id, 403);
    }
}
