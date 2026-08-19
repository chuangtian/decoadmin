<?php

namespace App\Services;

use App\Jobs\DeliverStoreAlertNotificationJob;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\StoreAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class NotificationCenterService
{
    /** @param array<string, mixed> $filters */
    public function paginate(Store $store, array $filters): LengthAwarePaginator
    {
        return StoreAlert::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)
            ->when(filled($filters['status'] ?? null), fn (Builder $query) => $query->where('delivery_status', $filters['status']))
            ->when(filled($filters['type'] ?? null), fn (Builder $query) => $query->where('type', $filters['type']))
            ->latest('occurred_at')->paginate(20)->withQueryString()->through(fn (StoreAlert $alert): array => [
                'id' => $alert->id, 'uuid' => $alert->uuid, 'type' => $alert->type, 'severity' => $alert->severity,
                'code' => $alert->code, 'title' => $alert->title, 'message' => $alert->message,
                'delivery_status' => $alert->delivery_status, 'delivery_error' => $alert->delivery_error,
                'delivery_attempts' => $alert->delivery_attempts, 'channels' => data_get($alert->context, 'notification_channels', []),
                'occurred_at' => $alert->occurred_at?->toIso8601String(), 'notified_at' => $alert->notified_at?->toIso8601String(),
                'last_delivery_at' => $alert->last_delivery_at?->toIso8601String(),
            ]);
    }

    public function resend(Store $store, StoreAlert $alert, User $actor): void
    {
        abort_unless($alert->organization_id === $store->organization_id && $alert->store_id === $store->id, 404);
        $context = $alert->context ?? [];
        unset($context['notification_channels'], $context['notification_channel_statuses']);
        $alert->update(['delivery_status' => 'pending', 'delivery_error' => null, 'context' => $context]);
        DeliverStoreAlertNotificationJob::dispatch($alert->id)->onQueue('notifications');
        AuditLog::query()->create([
            'uuid' => (string) Str::uuid(), 'organization_id' => $store->organization_id, 'store_id' => $store->id,
            'user_id' => $actor->id, 'action' => 'store_alert_notification_resent', 'subject_type' => StoreAlert::class,
            'subject_id' => $alert->id, 'metadata' => ['alert_uuid' => $alert->uuid, 'code' => $alert->code],
        ]);
    }
}
