<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable(['organization_id', 'name', 'shopify_domain', 'shopify_shop_id', 'status', 'timezone', 'currency', 'country_code', 'plan_name', 'settings', 'created_by'])]
class Store extends Model
{
    use SoftDeletes;

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_members')
            ->withPivot(['status', 'invited_by', 'joined_at', 'deleted_at'])
            ->wherePivot('status', 'active')
            ->wherePivotNull('deleted_at')
            ->withTimestamps();
    }

    public function shopifyConnection(): HasOne
    {
        return $this->hasOne(ShopifyConnection::class);
    }

    public function appInstallations(): HasMany
    {
        return $this->hasMany(AppInstallation::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function latestSyncJob(): HasOne
    {
        return $this->hasOne(SyncJob::class)->latestOfMany();
    }

    public function syncJobs(): HasMany
    {
        return $this->hasMany(SyncJob::class);
    }

    public function syncStates(): HasMany
    {
        return $this->hasMany(StoreSyncState::class);
    }

    public function notificationSetting(): HasOne
    {
        return $this->hasOne(StoreNotificationSetting::class);
    }

    public function businessCredentials(): HasMany
    {
        return $this->hasMany(StoreBusinessCredential::class);
    }

    public function studentDiscountCampaign(): HasOne
    {
        return $this->hasOne(StudentDiscountCampaign::class);
    }

    public function studentDiscountClaims(): HasMany
    {
        return $this->hasMany(StudentDiscountClaim::class);
    }

    public function studentDiscountCodes(): HasMany
    {
        return $this->hasMany(StudentDiscountCode::class);
    }

    public function instagramAccount(): HasOne
    {
        return $this->hasOne(InstagramAccount::class);
    }

    public function instagramMedia(): HasMany
    {
        return $this->hasMany(InstagramMedia::class);
    }

    public function instagramGalleries(): HasMany
    {
        return $this->hasMany(InstagramGallery::class);
    }

    public function metaAdAccounts(): HasMany
    {
        return $this->hasMany(MetaAdAccount::class);
    }

    public function metaAdCampaigns(): HasMany
    {
        return $this->hasMany(MetaAdCampaign::class);
    }

    public function metaAdSets(): HasMany
    {
        return $this->hasMany(MetaAdSet::class);
    }

    public function metaAds(): HasMany
    {
        return $this->hasMany(MetaAd::class);
    }

    public function metaAdCreatives(): HasMany
    {
        return $this->hasMany(MetaAdCreative::class);
    }

    public function metaAdInsights(): HasMany
    {
        return $this->hasMany(MetaAdInsight::class);
    }

    public function advertisingChannelAccounts(): HasMany
    {
        return $this->hasMany(AdvertisingChannelAccount::class);
    }

    public function advertisingChannelDailyMetrics(): HasMany
    {
        return $this->hasMany(AdvertisingChannelDailyMetric::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(StoreAlert::class);
    }

    public function storefrontEvents(): HasMany
    {
        return $this->hasMany(StorefrontEvent::class);
    }

    public function analyticsSnapshots(): HasMany
    {
        return $this->hasMany(AnalyticsSnapshot::class);
    }

    public function amazonDailySales(): HasMany
    {
        return $this->hasMany(AmazonDailySale::class);
    }

    public function campaignActivities(): HasMany
    {
        return $this->hasMany(CampaignActivity::class);
    }

    public function campaignPlanningDocuments(): HasMany
    {
        return $this->hasMany(CampaignPlanningDocument::class);
    }

    public function paidAdvertisingGoalBoards(): HasMany
    {
        return $this->hasMany(PaidAdvertisingGoalBoard::class);
    }

    public function paidAdvertisingGoalRecords(): HasMany
    {
        return $this->hasMany(PaidAdvertisingGoalRecord::class);
    }

    public function financeEntries(): HasMany
    {
        return $this->hasMany(FinanceEntry::class);
    }

    public function hasMember(User|int $user): bool
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return $this->members()->whereKey($userId)->exists();
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'shopify_shop_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Store $store): void {
            $store->analytics_ingest_key ??= (string) Str::uuid();
        });
    }
}
