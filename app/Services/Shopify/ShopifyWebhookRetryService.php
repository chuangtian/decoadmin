<?php

namespace App\Services\Shopify;

use App\Jobs\ProcessShopifyWebhook;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopifyWebhookRetryService
{
    public function retry(WebhookEvent $event, User $actor): WebhookEvent
    {
        $event = DB::transaction(function () use ($event, $actor): WebhookEvent {
            $locked = WebhookEvent::query()->lockForUpdate()->findOrFail($event->getKey());

            if ($locked->status !== 'failed') {
                throw ValidationException::withMessages([
                    'webhook' => '只有处理失败的 Webhook Event 可以重试。',
                ]);
            }

            $previousStatus = $locked->status;
            $locked->forceFill([
                'status' => 'pending',
                'processed_at' => null,
                'next_retry_at' => null,
                'last_error' => null,
            ])->save();

            AuditLog::query()->create([
                'organization_id' => $locked->organization_id,
                'store_id' => $locked->store_id,
                'user_id' => $actor->getKey(),
                'action' => 'shopify_webhook_retried',
                'subject_type' => $locked->getMorphClass(),
                'subject_id' => $locked->getKey(),
                'old_values' => ['status' => $previousStatus],
                'new_values' => ['status' => 'pending'],
                'metadata' => [
                    'webhook_id' => $locked->webhook_id,
                    'topic' => $locked->topic,
                    'previous_status' => $previousStatus,
                    'new_status' => 'pending',
                    'attempts' => $locked->attempts,
                ],
            ]);

            return $locked;
        });

        ProcessShopifyWebhook::dispatch($event->getKey())->onQueue('shopify-webhook');

        return $event;
    }
}
