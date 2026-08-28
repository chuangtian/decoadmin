<?php

namespace App\Services\StudentDiscount;

use App\Models\AuditLog;
use App\Models\StudentDiscountClaim;
use App\Models\StudentDiscountEvidenceDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StudentDiscountEvidenceCleanupService
{
    public function cleanup(int $deletionId): void
    {
        $deletion = StudentDiscountEvidenceDeletion::query()
            ->whereKey($deletionId)
            ->whereIn('status', ['pending', 'failed'])
            ->first();
        if (! $deletion) {
            return;
        }

        try {
            $storage = Storage::disk((string) $deletion->disk);
            $path = (string) $deletion->path;
            if ($storage->exists($path) && ! $storage->delete($path)) {
                throw new RuntimeException('Storage did not delete the evidence object.');
            }
            if ($storage->exists($path)) {
                throw new RuntimeException('Evidence object is still present after deletion.');
            }
        } catch (Throwable) {
            StudentDiscountEvidenceDeletion::query()
                ->whereKey($deletion->id)
                ->whereIn('status', ['pending', 'failed'])
                ->update([
                    'status' => 'failed',
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error_code' => 'storage_delete_failed',
                    'last_attempted_at' => now(),
                    'updated_at' => now(),
                ]);

            throw new RuntimeException('Student discount evidence cleanup failed.');
        }

        DB::transaction(function () use ($deletion): void {
            $lockedDeletion = StudentDiscountEvidenceDeletion::query()
                ->whereKey($deletion->id)
                ->lockForUpdate()
                ->first();
            if (! $lockedDeletion || $lockedDeletion->status === 'completed') {
                return;
            }

            $completedAt = now();
            $lockedDeletion->forceFill([
                'status' => 'completed',
                'attempts' => $lockedDeletion->attempts + 1,
                'last_error_code' => null,
                'last_attempted_at' => $completedAt,
                'completed_at' => $completedAt,
                'disk' => null,
                'path' => null,
            ])->save();

            $claim = $lockedDeletion->claim_id === null
                ? null
                : StudentDiscountClaim::withTrashed()
                    ->where('organization_id', $lockedDeletion->organization_id)
                    ->where('store_id', $lockedDeletion->store_id)
                    ->whereKey($lockedDeletion->claim_id)
                    ->first();
            if (! $claim) {
                return;
            }

            if ($claim->evidence_deleted_at === null) {
                $claim->forceFill(['evidence_deleted_at' => $completedAt])->save();
            }
            $this->audit($claim, 'student_discount_claim_evidence_deleted', $lockedDeletion, [
                'evidence_deleted' => true,
            ]);
        });
    }

    public function markPermanentlyFailed(int $deletionId): void
    {
        $deletion = StudentDiscountEvidenceDeletion::query()->whereKey($deletionId)->first();
        if (! $deletion || $deletion->status === 'completed') {
            return;
        }

        $deletion->forceFill([
            'status' => 'failed',
            'last_error_code' => 'storage_delete_failed',
            'last_attempted_at' => now(),
        ])->save();

        $claim = $deletion->claim_id === null
            ? null
            : StudentDiscountClaim::withTrashed()
                ->where('organization_id', $deletion->organization_id)
                ->where('store_id', $deletion->store_id)
                ->whereKey($deletion->claim_id)
                ->first();
        if ($claim) {
            $this->audit($claim, 'student_discount_claim_evidence_cleanup_failed', $deletion, [
                'error_code' => 'storage_delete_failed',
            ]);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        StudentDiscountClaim $claim,
        string $action,
        StudentDiscountEvidenceDeletion $deletion,
        array $metadata,
    ): void {
        AuditLog::query()->create([
            'organization_id' => $claim->organization_id,
            'store_id' => $claim->store_id,
            'user_id' => null,
            'action' => $action,
            'subject_type' => StudentDiscountClaim::class,
            'subject_id' => $claim->id,
            'metadata' => [
                'scope' => 'store',
                'claim_uuid' => $claim->uuid,
                'cleanup_uuid' => $deletion->uuid,
                ...$metadata,
            ],
        ]);
    }
}
