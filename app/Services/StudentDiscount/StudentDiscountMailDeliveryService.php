<?php

namespace App\Services\StudentDiscount;

use App\Mail\StudentDiscountDecisionMail;
use App\Models\StudentDiscountClaim;
use App\Models\StudentDiscountCode;
use App\Services\SystemSettingsService;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class StudentDiscountMailDeliveryService
{
    public function __construct(private SystemSettingsService $settings) {}

    public function send(StudentDiscountClaim $claim, ?StudentDiscountCode $code): void
    {
        if (! $this->hasDeliveringTransport()) {
            $this->settings->applyRuntimeConfiguration();
        }
        if (! $this->hasDeliveringTransport()) {
            throw new RuntimeException('Student discount decision email delivery is not configured.');
        }

        try {
            Mail::to($claim->email)->send(new StudentDiscountDecisionMail($claim, $code));
        } catch (Throwable) {
            throw new RuntimeException('Student discount decision email delivery failed.');
        }
    }

    private function hasDeliveringTransport(): bool
    {
        $mailer = trim((string) config('mail.default'));

        return $mailer !== '' && $this->mailerDelivers($mailer, []);
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
