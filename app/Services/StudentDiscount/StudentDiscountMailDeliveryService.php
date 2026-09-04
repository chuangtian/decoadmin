<?php

namespace App\Services\StudentDiscount;

use App\Mail\StudentDiscountDecisionMail;
use App\Mail\StudentDiscountTemplatePreviewMail;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountCampaign;
use App\Models\StudentDiscountClaim;
use App\Models\StudentDiscountCode;
use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class StudentDiscountMailDeliveryService
{
    public function __construct(
        private SystemSettingsService $settings,
        private StudentDiscountEmailTemplateService $templates,
    ) {}

    public function send(StudentDiscountClaim $claim, ?StudentDiscountCode $code): void
    {
        if (! $this->hasDeliveringTransport()) {
            $this->settings->applyRuntimeConfiguration();
        }
        if (! $this->hasDeliveringTransport()) {
            throw new RuntimeException('Student discount decision email delivery is not configured.');
        }

        $campaign = StudentDiscountCampaign::query()
            ->where('organization_id', $claim->organization_id)
            ->where('store_id', $claim->store_id)
            ->first();
        $store = Store::query()
            ->where('organization_id', $claim->organization_id)
            ->whereKey($claim->store_id)
            ->first();
        $rendered = $this->templates->render($code ? 'approval' : 'rejection', $campaign?->email_templates, [
            'store_name' => (string) ($store?->name ?: config('app.name')),
            'store_url' => $store?->shopify_domain ? 'https://'.$store->shopify_domain : '',
            'cta_url' => ! $code && $store ? $this->verificationPageUrl($store) : null,
            'applicant_email' => $claim->email,
            'discount_code' => $code?->code,
            'expires_at' => $code?->expires_at?->toIso8601String(),
            'usage_limit' => $code?->usage_limit,
            'rejection_reason' => $claim->rejection_reason,
        ]);

        try {
            Mail::to($claim->email)->send(new StudentDiscountDecisionMail(
                $claim,
                $code,
                $rendered,
            ));
        } catch (Throwable) {
            throw new RuntimeException('Student discount decision email delivery failed.');
        }
    }

    /** @param array<string, mixed> $configuration */
    public function sendTest(Organization $organization, Store $store, User $actor, string $type, string $recipient, array $configuration): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        if (! $this->hasDeliveringTransport()) {
            $this->settings->applyRuntimeConfiguration();
        }
        if (! $this->hasDeliveringTransport()) {
            throw new RuntimeException('Student discount email delivery is not configured.');
        }

        $rendered = $this->templates->renderAdHoc($type, $configuration, [
            'store_name' => $store->name,
            'store_url' => 'https://'.$store->shopify_domain,
        ]);
        try {
            Mail::to($recipient)->send(new StudentDiscountTemplatePreviewMail($rendered));
        } catch (Throwable) {
            throw new RuntimeException('Student discount template test email delivery failed.');
        }

        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => 'student_discount_email_template_test_sent',
            'metadata' => ['scope' => 'store', 'template_type' => $type],
        ]);
    }

    private function hasDeliveringTransport(): bool
    {
        $mailer = trim((string) config('mail.default'));

        return $mailer !== '' && $this->mailerDelivers($mailer, []);
    }

    private function verificationPageUrl(Store $store): string
    {
        $proxyPath = '/'.trim((string) config('student_discount.active.proxy_path'), '/');

        return 'https://'.$store->shopify_domain.$proxyPath.'/verify';
    }

    /** @param list<string> $visited */
    private function mailerDelivers(string $mailer, array $visited): bool
    {
        if (in_array($mailer, $visited, true)) {
            return false;
        }

        $configuration = config("mail.mailers.{$mailer}");
        if (! is_array($configuration)) {
            return false;
        }

        $transport = trim((string) ($configuration['transport'] ?? ''));
        if ($transport === 'smtp') {
            return filled($configuration['host'] ?? null)
                && (int) ($configuration['port'] ?? 0) > 0
                && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
        }
        if (in_array($transport, ['ses', 'ses-v2', 'postmark', 'resend', 'sendmail'], true)) {
            return filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
        }
        if (! in_array($transport, ['failover', 'roundrobin'], true)) {
            return false;
        }

        $mailers = array_values(array_filter(
            (array) ($configuration['mailers'] ?? []),
            fn (mixed $child): bool => is_string($child) && trim($child) !== '',
        ));

        return $mailers !== [] && collect($mailers)->every(
            fn (string $child): bool => $this->mailerDelivers($child, [...$visited, $mailer]),
        );
    }
}
