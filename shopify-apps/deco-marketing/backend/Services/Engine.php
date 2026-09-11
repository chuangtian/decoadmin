<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use Carbon\CarbonImmutable;
use DecoMarketing\Models\Campaign;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Enrollment;
use DecoMarketing\Models\Flow;
use DecoMarketing\Models\Settings;
use DecoMarketing\Models\Template;
use DecoMarketing\Models\Waitlist;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Engine
{
    public function enroll(Store $store, Contact $contact, string $flowKey, string $sourceKey, array $context = [], ?CarbonImmutable $occurredAt = null): ?Enrollment
    {
        app(Guard::class)->store($store);
        abort_unless($contact->store_id === $store->id && $contact->organization_id === $store->organization_id, 403);
        $contact = Contact::forStore($store)->findOrFail($contact->id);
        $settings = app(Catalog::class)->settings($store);
        $flow = Flow::forStore($store)->where('key', $flowKey)->firstOrFail();
        $at = $occurredAt ?? CarbonImmutable::now();
        if (! $settings->enabled || ! $flow->enabled || $contact->suppressed || $contact->consent !== 'subscribed' || ($settings->cutover_at && $at->lt($settings->cutover_at))) {
            return null;
        }
        if (strlen($sourceKey) > 120 || $sourceKey === '') {
            throw ValidationException::withMessages(['source' => '触发编号无效。']);
        }
        $steps = [];
        foreach ($flow->steps as $step) {
            $template = Template::forStore($store)->where('key', $step['template'])->firstOrFail();
            if (! $template->published) {
                return null;
            }
            $steps[] = [...$step, 'content' => $template->published, 'version' => $template->version];
        }
        $context['mode'] = config('marketing.transport') === 'preview' ? 'preview' : 'live';
        $context['occurred_at'] = $at->toIso8601String();

        return Enrollment::firstOrCreate(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'contact_id' => $contact->id, 'flow_key' => $flowKey, 'source_key' => $sourceKey],
            ['steps' => $steps, 'context_encrypted' => $context, 'next_at' => $at->addMinutes($steps[0]['after_minutes'])]);
    }

    public function run(Store $store, int $limit = 50): array
    {
        app(Guard::class)->store($store);
        $lock = Cache::lock('marketing:store:'.$store->id, 600);
        if (! $lock->get()) {
            return ['processed' => 0, 'reason' => 'already_running'];
        }
        try {
            app(Events::class)->process($store);
            $settings = Settings::forStore($store)->first();
            if (! $settings?->enabled) {
                return ['processed' => 0, 'reason' => 'disabled'];
            }
            app(Warmup::class)->advance($store);
            $settings->refresh();
            app(Campaigns::class)->expand($store);
            $processed = 0;
            $due = Enrollment::forStore($store)->where('status', 'active')->where('next_at', '<=', now())->orderBy('next_at')->limit(min(100, max(1, $limit)))->get();
            foreach ($due as $enrollment) {
                $this->step($store, $enrollment);
                $processed++;
                // Bound lock ownership to less than TTL even if a provider is slow.
                if ($processed >= 3 && ! app()->runningUnitTests()) {
                    break;
                }
            }
            app(Campaigns::class)->complete($store);

            return ['processed' => $processed, 'reason' => null];
        } finally {
            $lock->release();
        }
    }

    private function step(Store $store, Enrollment $enrollment): void
    {
        app(Guard::class)->store($store);
        abort_unless($enrollment->store_id === $store->id && $enrollment->organization_id === $store->organization_id, 403);
        $contactLock = Cache::lock('marketing:contact:'.$enrollment->contact_id, 90);
        if (! $contactLock->get()) {
            return;
        }
        try {
            $enrollment->refresh();
            if ($enrollment->status !== 'active' || ! $enrollment->next_at || $enrollment->next_at->isFuture()) {
                return;
            }
            $settings = app(Catalog::class)->settings($store);
            if (! $settings->enabled) {
                return;
            }
            $contact = Contact::forStore($store)->findOrFail($enrollment->contact_id);
            $context = $enrollment->context_encrypted ?? [];
            $key = hash('sha256', 'marketing|'.$store->id.'|'.$enrollment->id.'|'.$enrollment->step);
            $delivery = Delivery::forStore($store)->where('dedupe_key', $key)->first();
            if ($delivery && in_array($delivery->status, ['sent', 'delivered', 'simulated', 'bounced', 'complained'], true)) {
                $this->advance($enrollment);

                return;
            }
            if ($delivery?->status === 'skipped' && $delivery->reason === 'template_disabled') {
                $this->advance($enrollment);

                return;
            }
            if ($delivery && in_array($delivery->status, ['failed', 'held', 'skipped'], true)) {
                $enrollment->update(['status' => 'stopped', 'stop_reason' => $delivery->reason, 'next_at' => null]);

                return;
            }
            if ($delivery?->status === 'sending' && $delivery->lease_until?->isFuture()) {
                return;
            }
            if ($delivery && $delivery->first_attempt_at && $delivery->first_attempt_at->lt(now()->subHours(23))) {
                $delivery->update(['status' => 'held', 'reason' => 'uncertain_requires_review', 'lease_until' => null]);
                $enrollment->update(['status' => 'held', 'stop_reason' => 'uncertain_requires_review', 'next_at' => null]);

                return;
            }
            if ($delivery?->retry_at?->isFuture()) {
                $enrollment->update(['next_at' => $delivery->retry_at]);

                return;
            }
            if ($contact->suppressed || $contact->consent !== 'subscribed') {
                $this->stop($enrollment, $delivery, $contact->suppressed ? 'suppressed' : 'unsubscribed');

                return;
            }
            if ($enrollment->campaign_id) {
                $campaign = Campaign::forStore($store)->findOrFail($enrollment->campaign_id);
                if ($campaign->status !== 'sending') {
                    return;
                }
                if ($campaign->smart_timing) {
                    $next = app(SendWindow::class)->next(CarbonImmutable::now(), $contact->timezone ?: $settings->timezone, $campaign->uuid.'|'.$contact->uuid);
                    if ($next->isFuture()) {
                        $enrollment->update(['next_at' => $next]);
                        return;
                    }
                }
                if (app(Campaigns::class)->unhealthy($store, $campaign)) {
                    $campaign->update(['status' => 'paused', 'stop_reason' => 'unsubscribe_limit']);

                    return;
                }
            } elseif (! Flow::forStore($store)->where('key', $enrollment->flow_key)->where('enabled', true)->exists()) {
                return;
            }
            $mode = config('marketing.transport') === 'preview' ? 'preview' : 'live';
            if (($context['mode'] ?? 'preview') !== $mode) {
                $this->stop($enrollment, $delivery, 'transport_mode_changed');

                return;
            }
            if ($mode === 'live' && ! app(Guard::class)->recipient($contact->email_encrypted)) {
                $this->stop($enrollment, $delivery, 'recipient_not_allowed');

                return;
            }
            if (! $delivery && $enrollment->campaign_id && $contact->last_sent_at?->gt(now()->subHours(48))) {
                $this->stop($enrollment, null, 'smart_sending');

                return;
            }
            if (! $delivery && $contact->last_sent_at?->gt(now()->subHours($settings->frequency_hours))) {
                $enrollment->update(['next_at' => $contact->last_sent_at->copy()->addHours($settings->frequency_hours)]);

                return;
            }
            if (! $delivery && $this->dailyCount($store, $settings) >= $settings->daily_limit) {
                $enrollment->update(['next_at' => now($settings->timezone)->addDay()->startOfDay()->utc()]);

                return;
            }
            if ($this->circuitOpen($store)) {
                $enrollment->update(['next_at' => now()->addHour()]);

                return;
            }
            // Authoritative check immediately before sending payment/checkout/inventory messages.
            if (isset($context['order_id']) || isset($context['checkout_id']) || isset($context['variant_id']) || isset($context['customer_id'])) {
                $reason = app(Shopify::class)->stopReason($store, $enrollment->flow_key, $context);
                if ($reason === 'verification_unavailable') {
                    $enrollment->update(['next_at' => now()->addMinutes(5), 'stop_reason' => $reason]);

                    return;
                }
                if ($reason) {
                    $this->stop($enrollment, $delivery, $reason);

                    return;
                }
            }
            $step = $enrollment->steps[$enrollment->step] ?? null;
            if (! $step) {
                $enrollment->update(['status' => 'completed', 'next_at' => null]);

                return;
            }
            if (isset($step['template']) && ! Template::forStore($store)->where('key', $step['template'])->where('enabled', true)->exists()) {
                if ($delivery && $delivery->first_attempt_at) {
                    $this->stop($enrollment, $delivery, 'template_disabled');

                    return;
                }
                Delivery::firstOrCreate(['dedupe_key' => $key], ['organization_id' => $store->organization_id, 'store_id' => $store->id, 'contact_id' => $contact->id, 'enrollment_id' => $enrollment->id, 'step' => $enrollment->step, 'template_key' => $step['template'], 'status' => 'skipped', 'reason' => 'template_disabled']);
                $this->advance($enrollment);

                return;
            }
            $delivery ??= Delivery::firstOrCreate(['dedupe_key' => $key], ['organization_id' => $store->organization_id, 'store_id' => $store->id, 'contact_id' => $contact->id, 'enrollment_id' => $enrollment->id, 'step' => $enrollment->step, 'template_key' => $step['template'] ?? null]);
            if (! $delivery->payload_encrypted) {
                try {
                    if (str_contains(implode(' ', $step['content']), '{couponCode}')) {
                        $code = $context['coupon_code'] ?? $settings->welcome_coupon;
                        $couponStatus = $code ? (app(Coupons::class)->check($store, $code)['status'] ?? 'unavailable') : 'not_configured';
                        if ($couponStatus === 'unavailable') {
                            $delivery->update(['status' => 'queued', 'reason' => 'coupon_verification_unavailable']);
                            $enrollment->update(['next_at' => now()->addMinutes(10)]);

                            return;
                        }
                        if ($couponStatus !== 'ACTIVE') {
                            throw ValidationException::withMessages(['content' => '优惠码未配置、失效或无法验证。']);
                        }
                        $context['coupon_code'] = $code;
                    }
                    if (str_contains(implode(' ', $step['content']), '{referralCode}') && empty($context['referral_code'])) {
                        $context['referral_code'] = app(ReferralContext::class)->code($store, $contact);
                    }
                    $delivery->update(['payload_encrypted' => app(Renderer::class)->render($store, $contact, $step['content'], $context, $delivery)]);
                } catch (ValidationException) {
                    $this->stop($enrollment, $delivery, 'invalid_content_or_destination');

                    return;
                }
            }
            $delivery->update(['status' => 'sending', 'first_attempt_at' => $delivery->first_attempt_at ?? now(), 'attempts' => $delivery->attempts + 1, 'lease_until' => now()->addSeconds(60), 'reason' => null]);
            $outcome = app(Transport::class)->send($store, $delivery);
            DB::transaction(function () use ($store, $enrollment, $delivery, $contact, $outcome) {
                $delivery = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
                // A provider callback may have confirmed the outcome while send() was waiting.
                if (! in_array($delivery->status, ['sent', 'delivered', 'bounced', 'complained'], true)) {
                    $delivery->update(['status' => $outcome['status'], 'provider_id' => $outcome['id'] ?? $delivery->provider_id, 'reason' => $outcome['reason'], 'lease_until' => null,
                        'sent_at' => $outcome['status'] === 'sent' ? now() : $delivery->sent_at,
                        'retry_at' => in_array($outcome['status'], ['retry', 'uncertain']) ? now()->addMinutes(5) : null]);
                }
                if (in_array($delivery->status, ['sent', 'delivered', 'bounced', 'complained', 'simulated'], true)) {
                    if ($delivery->status !== 'simulated') {
                        Contact::forStore($store)->whereKey($contact->id)->update(['last_sent_at' => now()]);
                    }
                    $this->advance($enrollment);
                } elseif (in_array($delivery->status, ['retry', 'uncertain'], true)) {
                    $enrollment->update(['next_at' => $delivery->retry_at]);
                } else {
                    $this->stop($enrollment, null, $delivery->reason ?? 'send_failed');
                }
            });
        } finally {
            $contactLock->release();
        }
    }

    private function stop(Enrollment $enrollment, ?Delivery $delivery, string $reason): void
    {
        $enrollment->update(['status' => 'stopped', 'stop_reason' => $reason, 'next_at' => null]);
        if ($delivery && ! in_array($delivery->status, ['sending', 'uncertain'])) {
            $delivery->update(['status' => 'skipped', 'reason' => $reason]);
        }
    }

    public function advance(Enrollment $enrollment): void
    {
        app(Guard::class)->store($enrollment->store);
        $fresh = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
        if ($fresh->step !== $enrollment->step || $fresh->status !== 'active') {
            return;
        }
        $next = $fresh->step + 1;
        $step = $fresh->steps[$next] ?? null;
        $at = CarbonImmutable::parse($fresh->context_encrypted['occurred_at'] ?? $fresh->created_at);
        if (! $step && $fresh->flow_key === 'back_in_stock' && isset($fresh->context_encrypted['waitlist_id'])) {
            $delivered = Delivery::where('enrollment_id', $fresh->id)->whereNotNull('sent_at')->exists();
            if ($delivered) {
                Waitlist::forStore($fresh->store)->whereKey($fresh->context_encrypted['waitlist_id'])->update(['status' => 'notified', 'notified_at' => now()]);
            }
        }
        $fresh->update(['step' => $next, 'status' => $step ? 'active' : 'completed', 'next_at' => $step ? $at->addMinutes($step['after_minutes']) : null, 'stop_reason' => null]);
    }

    public function dailyCount(Store $store, Settings $settings): int
    {
        return Delivery::forStore($store)->where('first_attempt_at', '>=', now($settings->timezone)->startOfDay()->utc())
            ->whereIn('status', ['sending', 'uncertain', 'held', 'sent', 'delivered', 'bounced', 'complained'])->count();
    }

    public function circuitOpen(Store $store): bool
    {
        $base = Delivery::forStore($store)->where('first_attempt_at', '>=', now()->subHour())->where('status', '!=', 'simulated');
        $total = (clone $base)->count();
        $bad = (clone $base)->whereIn('status', ['failed', 'uncertain', 'held'])->count();

        return $bad >= 10 && $bad * 2 >= $total;
    }
}
