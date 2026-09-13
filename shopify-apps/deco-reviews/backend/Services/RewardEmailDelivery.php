<?php

namespace DecoReviews\Services;

use App\Models\Store;
use App\Services\SystemSettingsService;
use DecoReviews\Models\Reward;
use DecoReviews\Models\RewardDelivery;
use Illuminate\Support\Facades\Mail;

class RewardEmailDelivery
{
    public function cancellationCode(Store $store, RewardDelivery $delivery): ?string
    {
        if (! in_array($delivery->type, RewardEmail::TYPES, true)) {
            return 'DELIVERY_TYPE_INVALID';
        }
        $reward = $delivery->relationLoaded('reward') ? $delivery->reward : null;
        if (! $reward || (int) $reward->organization_id !== (int) $store->organization_id || (int) $reward->store_id !== (int) $store->id
            || ! $reward->code || ! in_array($reward->status, ['issued', 'redeemed'], true)) {
            return 'REWARD_NOT_ISSUED';
        }
        if ($reward->status === 'redeemed') {
            return 'REWARD_ALREADY_REDEEMED';
        }
        if (! $reward->expires_at || $reward->expires_at->isPast()) {
            return 'REWARD_EXPIRED';
        }
        $settings = app(ReviewService::class)->settings($store);
        if (! $settings['rewards_enabled']) {
            return 'REWARDS_DISABLED';
        }
        if ($delivery->type === 'reward_reminder') {
            if (! $settings['reward_reminder_enabled']) {
                return 'REWARD_REMINDER_DISABLED';
            }
            $initialSent = RewardDelivery::where('organization_id', $store->organization_id)->where('store_id', $store->id)
                ->where('reward_id', $reward->id)->where('type', 'reward_issued')->where('status', 'sent')->exists();
            if (! $initialSent) {
                return 'REWARD_EMAIL_NOT_CONFIRMED';
            }
        }

        return null;
    }

    public function transportAllowed(Store $store, RewardDelivery $delivery): bool
    {
        app(ReviewService::class)->active($store);
        if (! config('deco_reviews.delivery_enabled') || ! in_array($store->shopify_domain, config('deco_reviews.automation_stores', []), true)) {
            return false;
        }
        if (config('deco_reviews.environment') !== 'production'
            && ! in_array(strtolower($delivery->recipient), array_map('strtolower', config('deco_reviews.recipient_allowlist', [])), true)) {
            return false;
        }
        app(SystemSettingsService::class)->applyRuntimeConfiguration();

        return config('mail.mailers.'.config('mail.default').'.transport') === 'smtp'
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(Store $store, RewardDelivery $delivery): bool
    {
        $fresh = $delivery->fresh();
        if (! $fresh || $fresh->status !== 'sending') {
            return false;
        }
        $reward = Reward::where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->whereKey($fresh->reward_id)->first();
        $fresh->setRelation('reward', $reward);
        if ($this->cancellationCode($store, $fresh) || ! $this->transportAllowed($store, $fresh)) {
            return false;
        }
        $content = app(RewardEmail::class)->content($store, $fresh->reward, $fresh->type);
        $settings = app(ReviewService::class)->settings($store);
        $sent = Mail::send(['html' => 'deco-reviews::emails.reward', 'text' => 'deco-reviews::emails.reward-text'], $content,
            function ($message) use ($store, $fresh, $settings, $content) {
                $senderName = trim(str_replace(["\r", "\n"], ' ', $store->name)).' Reviews';
                $message->from(config('mail.from.address'), $senderName);
                $message->to($fresh->recipient)->subject($content['subject']);
                if ($settings['reply_to']) {
                    $message->replyTo($settings['reply_to']);
                }
                $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $fresh->uuid.'@'.parse_url(config('app.url'), PHP_URL_HOST));
            });

        return $sent !== null;
    }
}
