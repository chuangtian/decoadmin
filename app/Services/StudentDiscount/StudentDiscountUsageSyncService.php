<?php

namespace App\Services\StudentDiscount;

use App\Jobs\SyncStudentDiscountCodeUsage;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountCode;
use App\Models\User;

class StudentDiscountUsageSyncService
{
    public const BATCH_LIMIT = 50;

    /** @param list<string> $codeUuids @return array{requested: int, queued: int} */
    public function queue(Organization $organization, Store $store, User $actor, array $codeUuids): array
    {
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless($actor->canAccessStore($store)
            && $actor->hasPermission('student_discount.claim.read', $organization, $store), 403);
        $uuids = collect($codeUuids)->filter(fn (mixed $uuid): bool => is_string($uuid) && $uuid !== '')
            ->unique()->take(self::BATCH_LIMIT)->values();
        $codes = StudentDiscountCode::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->whereHas('claim')
            ->whereIn('uuid', $uuids)
            ->limit(self::BATCH_LIMIT)
            ->get(['id', 'organization_id', 'store_id']);

        $codes->each(fn (StudentDiscountCode $code) => SyncStudentDiscountCodeUsage::dispatch(
            (int) $code->organization_id,
            (int) $code->store_id,
            (int) $code->id,
        ));

        return ['requested' => $uuids->count(), 'queued' => $codes->count()];
    }
}
