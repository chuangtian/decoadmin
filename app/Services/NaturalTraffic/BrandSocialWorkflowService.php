<?php

namespace App\Services\NaturalTraffic;

use App\Models\AuditLog;
use App\Models\BrandSocialDailyReview;
use App\Models\BrandSocialPostState;
use App\Models\BrandSocialWeeklyReport;
use App\Models\FeishuBitableRecord;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class BrandSocialWorkflowService
{
    public function __construct(private NaturalTrafficDashboardService $dashboard) {}

    public function setPostVisibility(
        Store $store,
        User $actor,
        string $sourceSection,
        string $sourceTableKey,
        string $sourceRecordId,
        bool $hidden,
    ): BrandSocialPostState {
        $sourceExists = FeishuBitableRecord::query()
            ->where('organization_id', (int) $store->organization_id)
            ->where('store_id', (int) $store->id)
            ->where('source_record_id', $sourceRecordId)
            ->whereHas('table', fn ($query) => $query
                ->where('source_section', $sourceSection)
                ->where('source_table_id', $sourceTableKey))
            ->exists();
        abort_unless($sourceExists, 404);

        return DB::transaction(function () use ($store, $actor, $sourceSection, $sourceTableKey, $sourceRecordId, $hidden): BrandSocialPostState {
            $state = BrandSocialPostState::query()->firstOrNew([
                'store_id' => (int) $store->id,
                'source_section' => $sourceSection,
                'source_table_key' => $sourceTableKey,
                'source_record_id' => $sourceRecordId,
            ]);
            $oldHidden = $state->exists ? (bool) $state->is_hidden : false;
            $state->fill([
                'organization_id' => (int) $store->organization_id,
                'is_hidden' => $hidden,
                'hidden_by' => $hidden ? (int) $actor->id : null,
                'hidden_at' => $hidden ? now() : null,
            ])->save();

            $this->audit($store, $actor, 'brand_social_post_visibility_updated', $state, [
                'source_section' => $sourceSection,
                'source_table_key' => $sourceTableKey,
                'source_record_id' => $sourceRecordId,
                'old_hidden' => $oldHidden,
                'new_hidden' => $hidden,
                'idempotent' => $oldHidden === $hidden,
            ]);

            return $state;
        });
    }

    /** @param array<string, mixed> $values */
    public function upsertDailyReview(Store $store, User $actor, array $values): BrandSocialDailyReview
    {
        return DB::transaction(function () use ($store, $actor, $values): BrandSocialDailyReview {
            $review = BrandSocialDailyReview::query()->firstOrNew([
                'store_id' => (int) $store->id,
                'review_date' => $values['review_date'],
            ]);
            $created = ! $review->exists;
            $review->fill([
                'organization_id' => (int) $store->organization_id,
                'status' => $values['status'],
                'core_data' => $this->nullableText($values['core_data'] ?? null),
                'top_content' => $this->nullableText($values['top_content'] ?? null),
                'low_content' => $this->nullableText($values['low_content'] ?? null),
                'recommendations' => $this->nullableText($values['recommendations'] ?? null),
                'published_at' => $values['status'] === 'published' ? ($review->published_at ?? now()) : null,
                'created_by' => $review->created_by ?: (int) $actor->id,
                'updated_by' => (int) $actor->id,
            ])->save();

            $this->audit($store, $actor, $created ? 'brand_social_daily_review_created' : 'brand_social_daily_review_updated', $review, [
                'review_date' => $review->review_date?->toDateString(),
                'status' => $review->status,
            ]);

            return $review;
        });
    }

    public function deleteDailyReview(Store $store, User $actor, BrandSocialDailyReview $review): void
    {
        $this->guardScope($store, $review);
        DB::transaction(function () use ($store, $actor, $review): void {
            $this->audit($store, $actor, 'brand_social_daily_review_deleted', $review, [
                'review_date' => $review->review_date?->toDateString(),
                'status' => $review->status,
            ]);
            $review->delete();
        });
    }

    /** @param array<string, mixed> $values */
    public function upsertWeeklyReport(Store $store, User $actor, array $values): BrandSocialWeeklyReport
    {
        $weekStart = CarbonImmutable::parse((string) $values['week_start'], $store->timezone ?: 'UTC')
            ->startOfWeek(CarbonInterface::SUNDAY);
        $weekEnd = $weekStart->endOfWeek(CarbonInterface::SATURDAY);
        $week = $weekStart->toDateString();
        $dashboard = $this->dashboard->forChannel($store, 'brand-media', [
            'date_from' => $week,
            'date_to' => $weekEnd->toDateString(),
            'comparison' => 'previous',
            'weekly_week' => $week,
        ]);
        $snapshot = $dashboard['selected_weekly_report'] ?? null;

        return DB::transaction(function () use ($store, $actor, $values, $weekStart, $weekEnd, $snapshot): BrandSocialWeeklyReport {
            $report = BrandSocialWeeklyReport::query()->firstOrNew([
                'store_id' => (int) $store->id,
                'week_start' => $weekStart->toDateString(),
            ]);
            $created = ! $report->exists;
            $report->fill([
                'organization_id' => (int) $store->organization_id,
                'week_end' => $weekEnd->toDateString(),
                'title' => trim((string) $values['title']),
                'status' => $values['status'],
                'summary' => $this->nullableText($values['summary'] ?? null),
                'metrics_snapshot' => is_array($snapshot) ? $snapshot : null,
                'published_at' => $values['status'] === 'published' ? ($report->published_at ?? now()) : null,
                'created_by' => $report->created_by ?: (int) $actor->id,
                'updated_by' => (int) $actor->id,
            ])->save();

            $this->audit($store, $actor, $created ? 'brand_social_weekly_report_created' : 'brand_social_weekly_report_updated', $report, [
                'week_start' => $report->week_start?->toDateString(),
                'status' => $report->status,
            ]);

            return $report;
        });
    }

    public function deleteWeeklyReport(Store $store, User $actor, BrandSocialWeeklyReport $report): void
    {
        $this->guardScope($store, $report);
        DB::transaction(function () use ($store, $actor, $report): void {
            $this->audit($store, $actor, 'brand_social_weekly_report_deleted', $report, [
                'week_start' => $report->week_start?->toDateString(),
                'status' => $report->status,
            ]);
            $report->delete();
        });
    }

    private function guardScope(Store $store, Model $model): void
    {
        abort_unless(
            (int) data_get($model, 'organization_id') === (int) $store->organization_id
            && (int) data_get($model, 'store_id') === (int) $store->id,
            404,
        );
    }

    private function nullableText(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Store $store, User $actor, string $action, Model $subject, array $metadata): void
    {
        AuditLog::query()->create([
            'organization_id' => (int) $store->organization_id,
            'store_id' => (int) $store->id,
            'user_id' => (int) $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'metadata' => ['scope' => 'store', ...$metadata],
        ]);
    }
}
