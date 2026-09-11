<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\StoreAlert;
use App\Models\User;
use App\Support\SafeDiagnosticMessage;
use App\Support\StoreAlertPresentation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class StoreAlertQueryService
{
    /** @return LengthAwarePaginator<StoreAlert> */
    public function paginate(Store $store, ?string $status, ?string $type): LengthAwarePaginator
    {
        return StoreAlert::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->id)
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($type, fn ($query) => $query->where('type', $type))
            ->latest('occurred_at')
            ->paginate(25)
            ->withQueryString()
            ->through(function (StoreAlert $alert): StoreAlert {
                $alert->title = StoreAlertPresentation::title($alert);
                $alert->message = SafeDiagnosticMessage::sanitize($alert->message);
                $alert->delivery_error = filled($alert->delivery_error)
                    ? SafeDiagnosticMessage::sanitize($alert->delivery_error, '通知发送失败。', 500)
                    : null;

                return $alert;
            });
    }

    public function acknowledge(Store $store, StoreAlert $alert, User $actor): void
    {
        $this->guardScope($store, $alert);
        if ($alert->status !== 'open') {
            return;
        }

        $alert->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'acknowledged_by' => $actor->id]);
        $this->audit($store, $alert, $actor, 'store_alert_acknowledged');
    }

    public function resolve(Store $store, StoreAlert $alert, User $actor): void
    {
        $this->guardScope($store, $alert);
        if ($alert->status === 'resolved') {
            return;
        }

        $alert->update(['status' => 'resolved', 'resolved_at' => now()]);
        $this->audit($store, $alert, $actor, 'store_alert_resolved');
    }

    private function guardScope(Store $store, StoreAlert $alert): void
    {
        abort_unless($alert->organization_id === $store->organization_id && $alert->store_id === $store->id, 404);
    }

    private function audit(Store $store, StoreAlert $alert, User $actor, string $action): void
    {
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => StoreAlert::class,
            'subject_id' => $alert->id,
            'metadata' => ['alert_uuid' => $alert->uuid, 'alert_type' => $alert->type, 'new_status' => $alert->status],
        ]);
    }
}
