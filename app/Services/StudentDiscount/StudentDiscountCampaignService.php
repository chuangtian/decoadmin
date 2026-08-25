<?php

namespace App\Services\StudentDiscount;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\StudentDiscountCampaign;
use App\Models\User;
use Illuminate\Support\Arr;

class StudentDiscountCampaignService
{
    public function getOrCreate(Organization $organization, Store $store, ?User $actor = null): StudentDiscountCampaign
    {
        $this->assertScope($organization, $store);

        return StudentDiscountCampaign::query()->firstOrCreate(
            ['organization_id' => $organization->id, 'store_id' => $store->id],
            ['created_by' => $actor?->id, 'education_email_domains' => []],
        );
    }

    public function update(Organization $organization, Store $store, array $values, User $actor): StudentDiscountCampaign
    {
        $this->assertScope($organization, $store);
        $campaign = $this->getOrCreate($organization, $store, $actor);
        $before = $this->snapshot($campaign);
        $values['education_email_domains'] = collect($values['education_email_domains'] ?? [])
            ->map(fn (string $domain): string => strtolower(trim($domain, " .\t\n\r\0\x0B")))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $values['target_ids'] = collect($values['target_ids'] ?? [])->map(fn ($id) => trim((string) $id))->filter()->unique()->values()->all();
        $campaign->fill([...$values, 'updated_by' => $actor->id])->save();

        AuditLog::query()->create([
            'organization_id' => $organization->id,
            'store_id' => $store->id,
            'user_id' => $actor->id,
            'action' => 'student_discount_campaign_updated',
            'subject_type' => StudentDiscountCampaign::class,
            'subject_id' => $campaign->id,
            'old_values' => $before,
            'new_values' => $this->snapshot($campaign),
            'metadata' => ['scope' => 'store'],
        ]);

        return $campaign;
    }

    public function assertScope(Organization $organization, Store $store): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
    }

    private function snapshot(StudentDiscountCampaign $campaign): array
    {
        return Arr::only($campaign->attributesToArray(), [
            'enabled', 'code_prefix', 'discount_type', 'discount_value', 'applies_to', 'target_ids',
            'combines_with_order_discounts', 'combines_with_product_discounts',
            'combines_with_shipping_discounts', 'usage_limit', 'validity_days', 'education_email_domains',
        ]);
    }
}
