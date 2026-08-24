<?php

namespace App\Services\Reputation;

use App\Models\AuditLog;
use App\Models\ReputationGoal;
use App\Models\ReputationMention;
use App\Models\ReputationResourceRequest;
use App\Models\ReputationRisk;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReputationWorkflowService
{
    /** @param array<string, mixed> $values */
    public function createMention(Store $store, User $actor, array $values): ReputationMention
    {
        return DB::transaction(function () use ($store, $actor, $values): ReputationMention {
            $publishedAt = CarbonImmutable::parse((string) $values['published_at'], $store->timezone ?: 'UTC')->utc();
            $canonicalKey = hash('sha256', 'manual|'.Str::uuid());
            $rating = (float) $values['rating'];
            $url = trim((string) ($values['url'] ?? ''));

            $mention = ReputationMention::query()->create([
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->id,
                'origin' => 'manual',
                'source' => $values['source'],
                'canonical_key' => $canonicalKey,
                'source_sheets' => ['人工录入'],
                'source_payloads_encrypted' => null,
                'url' => $url ?: null,
                'url_hash' => $url === '' ? null : hash('sha256', $url),
                'title' => $values['title'] ?? null,
                'content' => $values['content'],
                'rating' => $rating,
                'week_number' => $values['week_number'] ?? $publishedAt->setTimezone($store->timezone ?: 'UTC')->isoWeek(),
                'published_at' => $publishedAt,
                'model_name' => $values['model_name'] ?? null,
                'order_reference_encrypted' => $values['order_reference'] ?? null,
                'processing_status' => $values['processing_status'] ?? null,
                'response_note' => $values['response_note'] ?? null,
                'metrics' => [],
                'is_negative' => $rating <= 2,
                'is_active' => true,
                'synced_at' => now(),
            ]);

            if ($rating <= 2) {
                $risk = ReputationRisk::query()->create([
                    'organization_id' => (int) $store->organization_id,
                    'store_id' => (int) $store->id,
                    'reputation_mention_id' => (int) $mention->id,
                    'origin' => 'manual',
                    'source_key' => $canonicalKey,
                    'description' => mb_substr((string) $mention->content, 0, 5000),
                    'severity' => 'medium',
                    'source' => $mention->source,
                    'recommended_action' => $mention->response_note,
                    'status' => 'pending',
                    'occurred_at' => $mention->published_at,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                $this->audit($store, $actor, 'reputation_risk_created_from_review', $risk, ['source' => $risk->source]);
            }

            $this->audit($store, $actor, 'reputation_mention_created', $mention, [
                'source' => $mention->source,
                'rating' => $mention->rating,
                'origin' => 'manual',
            ]);

            return $mention;
        });
    }

    /** @param array<string, mixed> $values */
    public function updateMention(Store $store, User $actor, ReputationMention $mention, array $values): ReputationMention
    {
        abort_unless((int) $mention->organization_id === (int) $store->organization_id && (int) $mention->store_id === (int) $store->id, 404);
        $oldStatus = $mention->processing_status;
        $mention->fill($values)->save();
        $this->audit($store, $actor, 'reputation_mention_updated', $mention, [
            'old_status' => $oldStatus,
            'new_status' => $mention->processing_status,
            'response_note_updated' => array_key_exists('response_note', $values),
        ]);

        return $mention;
    }

    /** @param array<string, mixed> $values */
    public function createRisk(Store $store, User $actor, array $values): ReputationRisk
    {
        return DB::transaction(function () use ($store, $actor, $values): ReputationRisk {
            $risk = ReputationRisk::query()->create([
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->id,
                'origin' => 'manual',
                'description' => $values['description'],
                'severity' => $values['severity'],
                'source' => $values['source'],
                'recommended_action' => $values['recommended_action'] ?? null,
                'status' => 'pending',
                'occurred_at' => $values['occurred_at'] ?? now(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->audit($store, $actor, 'reputation_risk_created', $risk, ['severity' => $risk->severity, 'source' => $risk->source]);

            return $risk;
        });
    }

    /** @param array<string, mixed> $values */
    public function updateRisk(Store $store, User $actor, ReputationRisk $risk, array $values): ReputationRisk
    {
        abort_unless((int) $risk->organization_id === (int) $store->organization_id && (int) $risk->store_id === (int) $store->id, 404);
        $old = ['status' => $risk->status, 'severity' => $risk->severity];
        $risk->fill([...$values, 'updated_by' => $actor->id]);
        if (($values['status'] ?? null) === 'resolved' && ! $risk->resolved_at) {
            $risk->resolved_at = now();
        } elseif (isset($values['status']) && $values['status'] !== 'resolved') {
            $risk->resolved_at = null;
        }
        $risk->save();
        $this->audit($store, $actor, 'reputation_risk_updated', $risk, [
            'old' => $old,
            'new' => ['status' => $risk->status, 'severity' => $risk->severity],
        ]);

        return $risk;
    }

    /** @param array<string, mixed> $values */
    public function createResource(Store $store, User $actor, array $values): ReputationResourceRequest
    {
        return DB::transaction(function () use ($store, $actor, $values): ReputationResourceRequest {
            $request = ReputationResourceRequest::query()->create([
                'organization_id' => (int) $store->organization_id,
                'store_id' => (int) $store->id,
                'description' => $values['description'],
                'request_type' => $values['request_type'],
                'priority' => $values['priority'],
                'owner_name' => $values['owner_name'] ?? null,
                'status' => 'pending',
                'due_date' => $values['due_date'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->audit($store, $actor, 'reputation_resource_created', $request, ['type' => $request->request_type, 'priority' => $request->priority]);

            return $request;
        });
    }

    /** @param array<string, mixed> $values */
    public function updateResource(Store $store, User $actor, ReputationResourceRequest $request, array $values): ReputationResourceRequest
    {
        abort_unless((int) $request->organization_id === (int) $store->organization_id && (int) $request->store_id === (int) $store->id, 404);
        $oldStatus = $request->status;
        $request->fill([...$values, 'updated_by' => $actor->id])->save();
        $this->audit($store, $actor, 'reputation_resource_updated', $request, ['old_status' => $oldStatus, 'new_status' => $request->status]);

        return $request;
    }

    /** @param array<string, float|int> $targets */
    public function upsertGoals(Store $store, User $actor, string $month, array $targets): void
    {
        $monthDate = CarbonImmutable::parse($month, $store->timezone ?: 'UTC')->startOfMonth()->toDateString();
        DB::transaction(function () use ($store, $actor, $monthDate, $targets): void {
            foreach (ReputationDashboardService::GOAL_METRICS as $metric) {
                ReputationGoal::query()->updateOrCreate(
                    ['store_id' => (int) $store->id, 'month' => $monthDate, 'metric' => $metric],
                    [
                        'organization_id' => (int) $store->organization_id,
                        'target_value' => max(0, (float) ($targets[$metric] ?? 0)),
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ],
                );
            }
            $this->audit($store, $actor, 'reputation_goals_updated', $store, ['month' => $monthDate, 'metrics' => ReputationDashboardService::GOAL_METRICS]);
        });
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Store $store, User $actor, string $action, object $subject, array $metadata): void
    {
        AuditLog::query()->create([
            'organization_id' => (int) $store->organization_id,
            'store_id' => (int) $store->id,
            'user_id' => (int) $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => data_get($subject, 'id'),
            'metadata' => ['scope' => 'store', ...$metadata],
        ]);
    }
}
