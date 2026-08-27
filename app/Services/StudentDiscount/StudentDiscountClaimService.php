<?php

namespace App\Services\StudentDiscount;

use App\Exceptions\StudentDiscountException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountCampaign;
use App\Models\StudentDiscountClaim;
use App\Models\StudentDiscountCode;
use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudentDiscountClaimService
{
    public function __construct(
        private StudentDiscountCampaignService $campaigns,
        private EducationEmailDomainService $domains,
        private GeminiStudentIdRecognitionService $gemini,
        private StudentDiscountCodeService $codes,
        private SystemSettingsService $settings,
    ) {}

    /** @return array{claim: StudentDiscountClaim, code: StudentDiscountCode|null, claim_token: string} */
    public function submit(Store $store, string $email, ?UploadedFile $evidence, string $idempotencyKey): array
    {
        $organization = $store->organization;
        $campaign = $this->campaigns->getOrCreate($organization, $store);
        if (! $campaign->enabled) {
            throw new StudentDiscountException('CAMPAIGN_DISABLED', '该店铺当前未开放学生优惠。', 409);
        }

        $normalizedEmail = strtolower(trim($email));
        $evidenceHash = 'none';
        if ($evidence) {
            $realPath = $evidence->getRealPath();
            $evidenceHash = is_string($realPath) ? hash_file('sha256', $realPath) : false;
            if (! is_string($evidenceHash)) {
                throw new StudentDiscountException('EVIDENCE_UNREADABLE', '无法读取上传的学生证文件，请重新选择后提交。', 422);
            }
        }
        $fingerprint = hash('sha256', implode('|', [
            $normalizedEmail,
            (string) ($evidence?->getSize() ?? 0),
            (string) ($evidence?->getMimeType() ?? 'none'),
            $evidenceHash,
        ]));
        $duplicateRequest = DB::table('student_discount_claim_idempotencies')
            ->where('store_id', $store->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($duplicateRequest) {
            if (! hash_equals((string) $duplicateRequest->request_fingerprint, $fingerprint)) {
                throw new StudentDiscountException('IDEMPOTENCY_CONFLICT', '该幂等键已用于不同的申请内容。', 409);
            }
            $duplicate = StudentDiscountClaim::query()->findOrFail($duplicateRequest->claim_id);

            return ['claim' => $duplicate, 'code' => $duplicate->discountCode, 'claim_token' => $duplicate->claim_token_encrypted];
        }

        $domainFastPass = $this->domains->matches($normalizedEmail, $campaign->education_email_domains ?? []);
        if ($domainFastPass && ($reusable = $this->codes->reusableForEmail($store, $normalizedEmail))) {
            return [
                'claim' => $reusable->claim,
                'code' => $reusable,
                'claim_token' => $reusable->claim->claim_token_encrypted,
            ];
        }
        if (! $domainFastPass && ! $evidence) {
            throw new StudentDiscountException('EVIDENCE_REQUIRED', '非教育邮箱需要上传学生证。');
        }

        $claim = null;
        $token = '';
        $oldEvidence = null;
        $oldDisk = null;
        $wasDuplicate = false;

        DB::transaction(function () use (&$claim, &$token, &$oldEvidence, &$oldDisk, &$wasDuplicate, $campaign, $organization, $store, $normalizedEmail, $email, $domainFastPass, $idempotencyKey, $fingerprint): void {
            StudentDiscountCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $duplicateRequest = DB::table('student_discount_claim_idempotencies')
                ->where('store_id', $store->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($duplicateRequest) {
                if (! hash_equals((string) $duplicateRequest->request_fingerprint, $fingerprint)) {
                    throw new StudentDiscountException('IDEMPOTENCY_CONFLICT', '该幂等键已用于不同的申请内容。', 409);
                }
                $claim = StudentDiscountClaim::query()->findOrFail($duplicateRequest->claim_id);
                $token = $claim->claim_token_encrypted;
                $wasDuplicate = true;

                return;
            }

            $claim = StudentDiscountClaim::query()
                ->where('store_id', $store->id)
                ->where('normalized_email', $normalizedEmail)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();
            $token = $claim?->claim_token_encrypted ?: Str::random(64);
            $oldEvidence = $claim?->evidence_path;
            $oldDisk = $claim?->evidence_disk;
            if ($claim) {
                $claim->forceFill([
                    'email' => trim($email),
                    'source' => $domainFastPass ? 'education_email' : 'student_id',
                    'review_method' => null,
                    'recognition_result' => null,
                    'confidence' => null,
                    'model_name' => null,
                    'recognized_at' => null,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'submission_count' => $claim->submission_count + 1,
                ])->save();
                $this->audit($claim, null, 'student_discount_claim_resubmitted', ['submission_count' => $claim->submission_count]);
            } else {
                $claim = StudentDiscountClaim::query()->create([
                    'organization_id' => $organization->id,
                    'store_id' => $store->id,
                    'email' => trim($email),
                    'normalized_email' => $normalizedEmail,
                    'source' => $domainFastPass ? 'education_email' : 'student_id',
                    'status' => 'pending',
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'claim_token_hash' => hash('sha256', $token),
                    'claim_token_encrypted' => $token,
                ]);
                $this->audit($claim, null, 'student_discount_claim_submitted');
            }

            DB::table('student_discount_claim_idempotencies')->insert([
                'store_id' => $store->id,
                'claim_id' => $claim->id,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'created_at' => now(),
            ]);
        });

        if ($wasDuplicate) {
            return ['claim' => $claim, 'code' => $claim->discountCode, 'claim_token' => $token];
        }

        if ($evidence) {
            $path = $evidence->storeAs(
                "student-discounts/{$organization->id}/{$store->id}/{$claim->uuid}",
                'student-id.'.strtolower($evidence->extension()),
                'local',
            );
            $claim->forceFill([
                'evidence_disk' => 'local',
                'evidence_path' => $path,
                'evidence_mime' => $evidence->getMimeType(),
                'evidence_size' => $evidence->getSize(),
                'evidence_deleted_at' => null,
            ])->save();
            if ($oldEvidence && ($oldEvidence !== $path || $oldDisk !== 'local')) {
                Storage::disk((string) $oldDisk)->delete((string) $oldEvidence);
            }
        }

        if ($domainFastPass) {
            $code = $this->approve($organization, $store, $claim, null, 'education_email', $campaign);

            return ['claim' => $claim->fresh(), 'code' => $code, 'claim_token' => $token];
        }

        $recognition = $this->gemini->recognize($claim);
        $claim->forceFill([
            'recognition_result' => $recognition['result'],
            'confidence' => $recognition['confidence'],
            'model_name' => $recognition['model'],
            'recognized_at' => now(),
            'review_method' => 'ai',
        ])->save();

        $threshold = $this->settings->studentAiForServer()['auto_approval_threshold'];
        $isStudentId = $recognition['ok'] && data_get($recognition, 'result.is_student_id') === true;
        if ($isStudentId && $recognition['confidence'] >= $threshold) {
            try {
                $code = $this->approve($organization, $store, $claim, null, 'ai', $campaign);

                return ['claim' => $claim->fresh(), 'code' => $code, 'claim_token' => $token];
            } catch (StudentDiscountException) {
                // Shopify failures fall back to the manual queue and never auto-reject.
            }
        }

        $this->audit($claim, null, 'student_discount_claim_queued_for_manual_review', [
            'recognition_status' => $recognition['ok']
                ? ($isStudentId ? 'below_threshold' : 'not_student_id')
                : $recognition['failure_code'],
        ]);

        return ['claim' => $claim->fresh(), 'code' => null, 'claim_token' => $token];
    }

    public function approve(
        Organization $organization,
        Store $store,
        StudentDiscountClaim $claim,
        ?User $actor,
        string $method = 'manual',
        ?StudentDiscountCampaign $campaign = null,
    ): StudentDiscountCode {
        $this->assertScope($organization, $store, $claim);
        if ($claim->status === 'rejected') {
            throw new StudentDiscountException('CLAIM_ALREADY_REJECTED', '已拒绝的申请不能改为通过，请申请人重新提交。', 409);
        }
        if ($claim->status === 'approved' && $claim->discountCode) {
            return $claim->discountCode;
        }

        $campaign ??= $this->campaigns->getOrCreate($organization, $store, $actor);
        $code = $this->codes->issue($claim, $campaign);
        $claim->forceFill([
            'status' => 'approved',
            'review_method' => $method,
            'reviewed_at' => now(),
            'reviewed_by' => $actor?->id,
            'rejection_reason' => null,
        ])->save();
        $this->audit($claim, $actor, 'student_discount_claim_approved', ['review_method' => $method, 'discount_code_uuid' => $code->uuid]);
        $this->codes->dispatchDecisionEmail($claim, $code);

        return $code;
    }

    public function reject(Organization $organization, Store $store, StudentDiscountClaim $claim, User $actor, string $reason): StudentDiscountClaim
    {
        $this->assertScope($organization, $store, $claim);
        if ($claim->status === 'approved') {
            throw new StudentDiscountException('CLAIM_ALREADY_APPROVED', '已通过并发码的申请不能拒绝。', 409);
        }
        if ($claim->status === 'rejected') {
            return $claim;
        }

        $claim->forceFill([
            'status' => 'rejected',
            'review_method' => 'manual',
            'reviewed_at' => now(),
            'reviewed_by' => $actor->id,
            'rejection_reason' => trim($reason),
        ])->save();
        $this->audit($claim, $actor, 'student_discount_claim_rejected', ['reason_present' => true]);
        $this->codes->dispatchDecisionEmail($claim, null);

        return $claim;
    }

    public function deleteClaim(Organization $organization, Store $store, string $claimUuid, User $actor): bool
    {
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);

        return DB::transaction(function () use ($organization, $store, $claimUuid, $actor): bool {
            $claim = StudentDiscountClaim::query()
                ->where('organization_id', $organization->id)
                ->where('store_id', $store->id)
                ->where('uuid', $claimUuid)
                ->lockForUpdate()
                ->first();
            if (! $claim) {
                return false;
            }

            $hadEvidence = filled($claim->evidence_path);
            if ($hadEvidence) {
                $disk = trim((string) $claim->evidence_disk);
                $path = trim((string) $claim->evidence_path);
                if ($disk === '' || $path === '') {
                    throw new StudentDiscountException('EVIDENCE_DELETE_FAILED', '证件文件无法安全删除，请稍后重试。', 500);
                }

                $storage = Storage::disk($disk);
                if ($storage->exists($path) && ! $storage->delete($path)) {
                    throw new StudentDiscountException('EVIDENCE_DELETE_FAILED', '证件文件无法安全删除，请稍后重试。', 500);
                }
                if ($storage->exists($path)) {
                    throw new StudentDiscountException('EVIDENCE_DELETE_FAILED', '证件文件无法安全删除，请稍后重试。', 500);
                }
            }

            $this->audit($claim, $actor, 'student_discount_claim_deleted', [
                'status' => $claim->status,
                'evidence_deleted' => $hadEvidence,
                'discount_code_present' => $claim->discountCode()->exists(),
            ]);
            DB::table('student_discount_claim_idempotencies')->where('claim_id', $claim->id)->delete();
            $claim->forceFill([
                'evidence_disk' => null,
                'evidence_path' => null,
                'evidence_mime' => null,
                'evidence_size' => null,
                'evidence_deleted_at' => $hadEvidence ? now() : $claim->evidence_deleted_at,
                'idempotency_key' => null,
                'request_fingerprint' => null,
            ])->save();
            $claim->delete();

            return true;
        });
    }

    public function verifyClaimToken(StudentDiscountClaim $claim, string $token): bool
    {
        return $token !== '' && hash_equals($claim->claim_token_hash, hash('sha256', $token));
    }

    private function assertScope(Organization $organization, Store $store, StudentDiscountClaim $claim): void
    {
        abort_unless($store->organization_id === $organization->id
            && $claim->organization_id === $organization->id
            && $claim->store_id === $store->id, 404);
    }

    private function audit(StudentDiscountClaim $claim, ?User $actor, string $action, array $metadata = []): void
    {
        AuditLog::query()->create([
            'organization_id' => $claim->organization_id,
            'store_id' => $claim->store_id,
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => StudentDiscountClaim::class,
            'subject_id' => $claim->id,
            'metadata' => ['scope' => 'store', 'claim_uuid' => $claim->uuid, ...$metadata],
        ]);
    }
}
