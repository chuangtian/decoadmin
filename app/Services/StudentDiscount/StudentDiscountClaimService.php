<?php

namespace App\Services\StudentDiscount;

use App\Exceptions\StudentDiscountException;
use App\Jobs\DeleteSupersededStudentDiscountEvidence;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountCampaign;
use App\Models\StudentDiscountClaim;
use App\Models\StudentDiscountCode;
use App\Models\StudentDiscountEvidenceDeletion;
use App\Models\User;
use App\Services\SystemSettingsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
        private StudentDiscountEvidenceCleanupService $evidenceCleanup,
        private SystemSettingsService $settings,
    ) {}

    /** @return array{claim: StudentDiscountClaim, code: StudentDiscountCode|null, claim_token: string} */
    public function submit(
        Store $store,
        ?string $name,
        string $email,
        ?UploadedFile $evidence,
        string $idempotencyKey,
        bool $privacyConsented,
    ): array {
        $organization = $store->organization;
        $campaign = $this->campaigns->getOrCreate($organization, $store);
        if (! $campaign->enabled) {
            throw new StudentDiscountException('CAMPAIGN_DISABLED', '该店铺当前未开放学生优惠。', 409);
        }

        $normalizedName = is_string($name) ? preg_replace('/\s+/u', ' ', trim($name)) : null;
        $normalizedName = is_string($normalizedName) && $normalizedName !== '' ? $normalizedName : null;
        $normalizedEmail = strtolower(trim($email));
        if ($evidence && $normalizedName === null) {
            throw new StudentDiscountException('NAME_REQUIRED', '上传学生证时必须填写姓名。', 422);
        }
        if ($evidence && ! $privacyConsented) {
            throw new StudentDiscountException('PRIVACY_CONSENT_REQUIRED', '上传学生证前必须同意隐私政策和服务条款。', 422);
        }
        $evidenceHash = 'none';
        if ($evidence) {
            $realPath = $evidence->getRealPath();
            $evidenceHash = is_string($realPath) ? hash_file('sha256', $realPath) : false;
            if (! is_string($evidenceHash)) {
                throw new StudentDiscountException('EVIDENCE_UNREADABLE', '无法读取上传的学生证文件，请重新选择后提交。', 422);
            }
        }
        $fingerprint = hash('sha256', implode('|', [
            $normalizedName ?? 'none',
            $normalizedEmail,
            (string) ($evidence?->getSize() ?? 0),
            (string) ($evidence?->getMimeType() ?? 'none'),
            $evidenceHash,
            $privacyConsented ? 'privacy-consent-v1' : 'privacy-consent-none',
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
        $hasPendingClaim = StudentDiscountClaim::query()
            ->where('store_id', $store->id)
            ->where('normalized_email', $normalizedEmail)
            ->where('status', 'pending')
            ->exists();
        if ($domainFastPass && ! $hasPendingClaim && ($reusable = $this->codes->reusableForEmail($store, $normalizedEmail))) {
            return [
                'claim' => $reusable->claim,
                'code' => $reusable,
                'claim_token' => $reusable->claim->claim_token_encrypted,
            ];
        }
        if (! $domainFastPass && ! $evidence) {
            throw new StudentDiscountException('EVIDENCE_REQUIRED', '非教育邮箱需要上传学生证。');
        }

        $claimUuid = (string) Str::uuid();
        $evidenceDisk = null;
        $evidencePath = null;
        $evidenceMime = null;
        $evidenceSize = null;
        if ($evidence) {
            try {
                $storedPath = $evidence->storeAs(
                    "student-discounts/{$organization->id}/{$store->id}/{$claimUuid}",
                    'student-id.'.strtolower($evidence->extension()),
                    'local',
                );
            } catch (\Throwable) {
                throw new StudentDiscountException('EVIDENCE_STORE_FAILED', '学生证文件暂时无法安全保存，请稍后重试。', 500);
            }
            if (! is_string($storedPath) || blank($storedPath)) {
                throw new StudentDiscountException('EVIDENCE_STORE_FAILED', '学生证文件暂时无法安全保存，请稍后重试。', 500);
            }
            $evidenceDisk = 'local';
            $evidencePath = $storedPath;
            $evidenceMime = $evidence->getMimeType();
            $evidenceSize = $evidence->getSize();
        }

        $claim = null;
        $token = '';
        $wasDuplicate = false;
        $evidenceDeletionIds = [];

        try {
            DB::transaction(function () use (&$claim, &$token, &$wasDuplicate, &$evidenceDeletionIds, $campaign, $organization, $store, $claimUuid, $normalizedName, $normalizedEmail, $email, $privacyConsented, $domainFastPass, $idempotencyKey, $fingerprint, $evidenceDisk, $evidencePath, $evidenceMime, $evidenceSize): void {
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

                $existingClaims = StudentDiscountClaim::query()
                    ->where('store_id', $store->id)
                    ->where('normalized_email', $normalizedEmail)
                    ->lockForUpdate()
                    ->get();
                $pendingClaims = $existingClaims->where('status', 'pending')->values();
                $submissionCount = max(1, ((int) $existingClaims->max('submission_count')) + 1);
                $token = Str::random(64);
                $claim = StudentDiscountClaim::query()->create([
                    'uuid' => $claimUuid,
                    'organization_id' => $organization->id,
                    'store_id' => $store->id,
                    'name' => $normalizedName,
                    'email' => trim($email),
                    'normalized_email' => $normalizedEmail,
                    'privacy_consented_at' => $privacyConsented ? now() : null,
                    'source' => $domainFastPass ? 'education_email' : 'student_id',
                    'status' => 'pending',
                    'evidence_disk' => $evidenceDisk,
                    'evidence_path' => $evidencePath,
                    'evidence_mime' => $evidenceMime,
                    'evidence_size' => $evidenceSize,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'claim_token_hash' => hash('sha256', $token),
                    'claim_token_encrypted' => $token,
                    'submission_count' => $submissionCount,
                ]);

                DB::table('student_discount_claim_idempotencies')->insert([
                    'store_id' => $store->id,
                    'claim_id' => $claim->id,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'created_at' => now(),
                ]);

                foreach ($pendingClaims as $pendingClaim) {
                    $hadEvidence = filled($pendingClaim->evidence_path);
                    if ($hadEvidence) {
                        $deletion = StudentDiscountEvidenceDeletion::query()->create([
                            'organization_id' => $pendingClaim->organization_id,
                            'store_id' => $pendingClaim->store_id,
                            'claim_id' => $pendingClaim->id,
                            'disk' => (string) $pendingClaim->evidence_disk,
                            'path' => (string) $pendingClaim->evidence_path,
                            'status' => 'pending',
                        ]);
                        $evidenceDeletionIds[] = $deletion->id;
                    }
                    $pendingClaim->forceFill([
                        'status' => 'voided',
                        'superseded_by_claim_id' => $claim->id,
                        'superseded_at' => now(),
                        'evidence_disk' => null,
                        'evidence_path' => null,
                        'evidence_mime' => null,
                        'evidence_size' => null,
                    ])->save();
                    $this->audit($pendingClaim, null, 'student_discount_claim_voided', [
                        'reason' => 'superseded',
                        'superseded_by_claim_uuid' => $claim->uuid,
                        'evidence_cleanup_status' => $hadEvidence ? 'pending' : 'not_required',
                    ]);
                }

                $this->audit($claim, null, 'student_discount_claim_submitted', [
                    'superseded_claim_uuids' => $pendingClaims->pluck('uuid')->values()->all(),
                ]);
            });
        } catch (\Throwable $exception) {
            if ($evidencePath !== null) {
                $this->deleteEvidenceLocation($evidenceDisk, $evidencePath);
            }

            throw $exception;
        }

        if ($wasDuplicate) {
            if ($evidencePath !== null) {
                $this->deleteEvidenceLocation($evidenceDisk, $evidencePath);
            }

            return ['claim' => $claim, 'code' => $claim->discountCode, 'claim_token' => $token];
        }

        foreach ($evidenceDeletionIds as $deletionId) {
            try {
                $this->evidenceCleanup->cleanup($deletionId);
            } catch (\Throwable) {
                try {
                    DeleteSupersededStudentDiscountEvidence::dispatch($deletionId);
                } catch (\Throwable) {
                    // The encrypted cleanup record remains retryable without exposing its location.
                }
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
            'recognition_failure_code' => $recognition['failure_code'],
            'recognized_at' => now(),
            'review_method' => 'ai',
        ])->save();

        $threshold = max(80.0, $this->settings->studentAiForServer()['auto_approval_threshold']);
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
        if ($claim->status === 'voided') {
            throw new StudentDiscountException('CLAIM_VOIDED', '该申请已因重新提交而作废，不能继续审核。', 409);
        }
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
        if ($claim->status === 'voided') {
            throw new StudentDiscountException('CLAIM_VOIDED', '该申请已因重新提交而作废，不能继续审核。', 409);
        }
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

        return Cache::lock("student-discount-claim-delete:{$store->id}:{$claimUuid}", 45)
            ->block(10, function () use ($organization, $store, $claimUuid, $actor): bool {
                $claim = StudentDiscountClaim::query()
                    ->where('organization_id', $organization->id)
                    ->where('store_id', $store->id)
                    ->where('uuid', $claimUuid)
                    ->first();
                if (! $claim) {
                    return false;
                }

                $discountCode = $claim->discountCode;
                if ($discountCode) {
                    $this->codes->delete($discountCode);
                }

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

                    $hadEvidence = $this->deleteEvidenceFile($claim);
                    $discountCode = $claim->discountCode()->lockForUpdate()->first();

                    $this->audit($claim, $actor, 'student_discount_claim_deleted', [
                        'status' => $claim->status,
                        'evidence_deleted' => $hadEvidence,
                        'discount_code_deleted' => (bool) $discountCode,
                    ]);
                    DB::table('student_discount_claim_idempotencies')->where('claim_id', $claim->id)->delete();
                    $discountCode?->delete();
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

    private function deleteEvidenceFile(StudentDiscountClaim $claim): bool
    {
        if (! filled($claim->evidence_path)) {
            return false;
        }

        $this->deleteEvidenceLocation($claim->evidence_disk, $claim->evidence_path);

        return true;
    }

    private function deleteEvidenceLocation(?string $disk, ?string $path): void
    {
        $disk = trim((string) $disk);
        $path = trim((string) $path);
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
