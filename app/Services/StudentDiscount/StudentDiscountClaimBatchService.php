<?php

namespace App\Services\StudentDiscount;

use App\Exceptions\StudentDiscountException;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

class StudentDiscountClaimBatchService
{
    public const BATCH_LIMIT = 30;

    public function __construct(
        private StudentDiscountClaimService $claims,
        private StudentDiscountCampaignService $campaigns,
    ) {}

    /** @param list<string> $claimUuids @return array<string, mixed> */
    public function approve(Organization $organization, Store $store, array $claimUuids, User $actor): array
    {
        $this->assertScope($organization, $store, $actor, 'student_discount.approve');
        $campaign = $this->campaigns->getOrCreate($organization, $store, $actor);

        return $this->process($organization, $store, $claimUuids, function (StudentDiscountClaim $claim) use ($organization, $store, $actor, $campaign): void {
            $this->claims->approve($organization, $store, $claim, $actor, 'manual', $campaign);
        }, 'approved');
    }

    /** @param list<string> $claimUuids @return array<string, mixed> */
    public function reject(Organization $organization, Store $store, array $claimUuids, User $actor, string $reason): array
    {
        $this->assertScope($organization, $store, $actor, 'student_discount.reject');

        return $this->process($organization, $store, $claimUuids, function (StudentDiscountClaim $claim) use ($organization, $store, $actor, $reason): void {
            DB::transaction(fn () => $this->claims->reject($organization, $store, $claim, $actor, $reason));
        }, 'rejected');
    }

    /**
     * @param  list<string>  $claimUuids
     * @param  callable(StudentDiscountClaim): void  $operation
     * @return array<string, mixed>
     */
    private function process(
        Organization $organization,
        Store $store,
        array $claimUuids,
        callable $operation,
        string $targetStatus,
    ): array {
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        $uuids = collect($claimUuids)->filter(fn (mixed $uuid): bool => is_string($uuid) && $uuid !== '')
            ->unique()->take(self::BATCH_LIMIT)->values();
        $items = [];

        foreach ($uuids as $uuid) {
            $claim = StudentDiscountClaim::query()
                ->where('organization_id', $organization->id)
                ->where('store_id', $store->id)
                ->where('uuid', $uuid)
                ->first();
            if (! $claim) {
                $items[] = $this->item($uuid, 'failed', 'CLAIM_NOT_FOUND', '申请不存在或不属于当前店铺。');

                continue;
            }
            if ($claim->status === $targetStatus) {
                $items[] = $this->item($uuid, 'unchanged', 'ALREADY_PROCESSED', '申请已处于目标状态。');

                continue;
            }
            if ($claim->status !== 'pending') {
                $items[] = $this->item($uuid, 'failed', 'CLAIM_NOT_PENDING', '仅待审核申请可以执行该操作。');

                continue;
            }

            try {
                $operation($claim);
                $items[] = $this->item($uuid, 'succeeded', null, '处理成功。');
            } catch (StudentDiscountException $exception) {
                $items[] = $this->item($uuid, 'failed', $exception->errorCode, $exception->getMessage());
            } catch (Throwable) {
                $items[] = $this->item($uuid, 'failed', 'STUDENT_DISCOUNT_BATCH_ITEM_FAILED', '该申请暂时无法处理，请稍后重试。');
            }
        }

        $statuses = collect($items)->countBy('status');

        return [
            'requested' => $uuids->count(),
            'succeeded' => (int) ($statuses['succeeded'] ?? 0),
            'unchanged' => (int) ($statuses['unchanged'] ?? 0),
            'failed' => (int) ($statuses['failed'] ?? 0),
            'items' => $items,
        ];
    }

    /** @return array{id: string, status: string, code: string|null, message: string} */
    private function item(string $uuid, string $status, ?string $code, string $message): array
    {
        return ['id' => $uuid, 'status' => $status, 'code' => $code, 'message' => $message];
    }

    private function assertScope(Organization $organization, Store $store, User $actor, string $permission): void
    {
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless($actor->canAccessStore($store) && $actor->hasPermission($permission, $organization, $store), 403);
    }
}
