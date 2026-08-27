<?php

namespace App\Services\StudentDiscount;

use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountClaim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class StudentDiscountClaimQueryService
{
    private const STATUSES = ['pending', 'approved', 'rejected'];

    private const SOURCES = ['education_email', 'student_id'];

    private const REVIEW_METHODS = ['education_email', 'ai', 'manual', 'unreviewed'];

    private const USAGE_STATUSES = ['unused', 'partially_used', 'used_up', 'expired'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function dashboard(
        Organization $organization,
        Store $store,
        User $actor,
        array $filters,
        bool $canViewEvidence,
    ): array {
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        abort_unless($actor->canAccessStore($store)
            && $actor->hasPermission('student_discount.claim.read', $organization, $store), 403);
        $filters = $this->normalizeFilters($filters);
        $query = StudentDiscountClaim::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id);

        $this->applyFilters($query, $store, $filters);

        $claims = $query
            ->with(['reviewer:id,name', 'discountCode'])
            ->latest('created_at')
            ->latest('id')
            ->paginate($filters['per_page'])
            ->withQueryString()
            ->through(fn (StudentDiscountClaim $claim): array => $this->presentClaim($claim, $canViewEvidence));

        $counts = StudentDiscountClaim::query()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'claims' => $claims,
            'counts' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'approved' => (int) ($counts['approved'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ],
            'filters' => $filters,
            'filter_options' => $this->filterOptions(),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function normalizeFilters(array $filters): array
    {
        return [
            'status' => in_array($filters['status'] ?? null, self::STATUSES, true) ? $filters['status'] : '',
            'source' => in_array($filters['source'] ?? null, self::SOURCES, true) ? $filters['source'] : '',
            'review_method' => in_array($filters['review_method'] ?? null, self::REVIEW_METHODS, true) ? $filters['review_method'] : '',
            'email' => mb_strtolower(trim((string) ($filters['email'] ?? ''))),
            'submitted_from' => (string) ($filters['submitted_from'] ?? ''),
            'submitted_to' => (string) ($filters['submitted_to'] ?? ''),
            'usage_status' => in_array($filters['usage_status'] ?? null, self::USAGE_STATUSES, true) ? $filters['usage_status'] : '',
            'per_page' => in_array((int) ($filters['per_page'] ?? 30), [20, 30, 50], true) ? (int) ($filters['per_page'] ?? 30) : 30,
        ];
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, Store $store, array $filters): void
    {
        $query
            ->when($filters['status'] !== '', fn (Builder $builder) => $builder->where('status', $filters['status']))
            ->when($filters['source'] !== '', fn (Builder $builder) => $builder->where('source', $filters['source']))
            ->when($filters['email'] !== '', fn (Builder $builder) => $builder->where('normalized_email', 'like', '%'.$this->escapeLike($filters['email']).'%'))
            ->when($filters['review_method'] === 'education_email', fn (Builder $builder) => $builder->where(function (Builder $nested): void {
                $nested->where('source', 'education_email')->orWhere('review_method', 'education_email');
            }))
            ->when(in_array($filters['review_method'], ['ai', 'manual'], true), fn (Builder $builder) => $builder->where('review_method', $filters['review_method']))
            ->when($filters['review_method'] === 'unreviewed', fn (Builder $builder) => $builder->whereNull('review_method'));

        $timezone = $store->timezone ?: 'UTC';
        if ($filters['submitted_from'] !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['submitted_from'], $timezone)->startOfDay()->utc());
        }
        if ($filters['submitted_to'] !== '') {
            $query->where('created_at', '<=', CarbonImmutable::parse($filters['submitted_to'], $timezone)->endOfDay()->utc());
        }
        if ($filters['usage_status'] !== '') {
            $query->whereHas('discountCode', fn (Builder $builder) => $this->applyUsageStatus($builder, $filters['usage_status']));
        }
    }

    private function applyUsageStatus(Builder $query, string $status): Builder
    {
        $now = now();

        return match ($status) {
            'unused' => $query->where('usage_count', 0)->where('expires_at', '>', $now),
            'partially_used' => $query->where('usage_count', '>', 0)
                ->whereColumn('usage_count', '<', 'usage_limit')
                ->where('expires_at', '>', $now),
            'used_up' => $query->whereColumn('usage_count', '>=', 'usage_limit'),
            'expired' => $query->whereColumn('usage_count', '<', 'usage_limit')->where('expires_at', '<=', $now),
            default => $query,
        };
    }

    /** @return array<string, mixed> */
    private function presentClaim(StudentDiscountClaim $claim, bool $canViewEvidence): array
    {
        $discount = $claim->discountCode?->refreshStatus();

        return [
            'id' => $claim->uuid,
            'email' => $claim->email,
            'source' => $claim->source,
            'status' => $claim->status,
            'review_method' => $claim->review_method,
            'confidence' => $claim->confidence === null ? null : (float) $claim->confidence,
            'model_name' => $claim->model_name,
            'recognition_failure_code' => $claim->recognition_failure_code,
            'recognition_result' => $canViewEvidence ? $claim->recognition_result : null,
            'has_evidence' => filled($claim->evidence_path),
            'submission_count' => $claim->submission_count,
            'reviewer' => $claim->reviewer?->name,
            'rejection_reason' => $claim->rejection_reason,
            'created_at' => $claim->created_at->toIso8601String(),
            'reviewed_at' => $claim->reviewed_at?->toIso8601String(),
            'discount' => $discount ? [
                'id' => $discount->uuid,
                'code' => $discount->code,
                'status' => $discount->status,
                'usage_count' => $discount->usage_count,
                'usage_limit' => $discount->usage_limit,
                'expires_at' => $discount->expires_at->toIso8601String(),
                'last_synced_at' => $discount->last_synced_at?->toIso8601String(),
            ] : null,
        ];
    }

    /** @return array<string, list<array{value: string, label: string}>> */
    private function filterOptions(): array
    {
        return [
            'statuses' => [
                ['value' => 'pending', 'label' => '待审核'],
                ['value' => 'approved', 'label' => '已通过'],
                ['value' => 'rejected', 'label' => '已拒绝'],
            ],
            'sources' => [
                ['value' => 'education_email', 'label' => '教育邮箱'],
                ['value' => 'student_id', 'label' => '学生证'],
            ],
            'review_methods' => [
                ['value' => 'education_email', 'label' => '教育邮箱快速通过'],
                ['value' => 'ai', 'label' => 'AI 自动验证'],
                ['value' => 'manual', 'label' => '人工审核'],
                ['value' => 'unreviewed', 'label' => '尚未验证'],
            ],
            'usage_statuses' => [
                ['value' => 'unused', 'label' => '未使用'],
                ['value' => 'partially_used', 'label' => '部分使用'],
                ['value' => 'used_up', 'label' => '已用完'],
                ['value' => 'expired', 'label' => '已失效'],
            ],
        ];
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
