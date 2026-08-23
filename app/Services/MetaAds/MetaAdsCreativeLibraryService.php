<?php

namespace App\Services\MetaAds;

use App\Models\MetaAdAccount;
use App\Models\MetaAdInsight;
use App\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MetaAdsCreativeLibraryService
{
    private const PER_PAGE = 6;

    /**
     * @param  array{account?: mixed, date_from?: mixed, date_to?: mixed, page?: mixed, content?: mixed}  $filters
     * @return array<string, mixed>
     */
    public function forStore(Store $store, array $filters = []): array
    {
        $timezone = $store->timezone ?: (string) config('app.timezone', 'UTC');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $dateFrom = $this->date($filters['date_from'] ?? null, $today->startOfMonth(), $timezone, 'date_from');
        $dateTo = $this->date($filters['date_to'] ?? null, $today, $timezone, 'date_to');

        if ($dateFrom->gt($dateTo)) {
            throw ValidationException::withMessages(['date_to' => '结束日期不能早于开始日期。']);
        }
        if ($dateFrom->diffInDays($dateTo) > 366) {
            throw ValidationException::withMessages(['date_to' => '单次查询范围不能超过 367 天。']);
        }

        $account = is_string($filters['account'] ?? null) && $filters['account'] !== ''
            ? (string) $filters['account']
            : 'all';
        if ($account !== 'all' && ! MetaAdAccount::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('meta_account_id', $account)
            ->exists()) {
            throw ValidationException::withMessages(['account' => '所选 Meta 广告账户不属于当前店铺。']);
        }

        $page = filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT, [
            'options' => ['default' => 1, 'min_range' => 1],
        ]);
        $content = ($filters['content'] ?? 'creative') === 'copy' ? 'copy' : 'creative';
        $metrics = $this->metrics($store, $dateFrom, $dateTo, $account);

        $paginator = DB::query()
            ->fromSub($metrics, 'metrics')
            ->join('meta_ads as ads', function ($join): void {
                $join->on('ads.organization_id', '=', 'metrics.organization_id')
                    ->on('ads.store_id', '=', 'metrics.store_id')
                    ->on('ads.meta_ad_id', '=', 'metrics.meta_ad_id');
            })
            ->join('meta_ad_creatives as creatives', function ($join): void {
                $join->on('creatives.organization_id', '=', 'ads.organization_id')
                    ->on('creatives.store_id', '=', 'ads.store_id')
                    ->on('creatives.meta_creative_id', '=', 'ads.meta_creative_id');
            })
            ->when(
                $content === 'copy',
                fn (Builder $query): Builder => $query
                    ->whereNotNull('creatives.body')
                    ->whereRaw("TRIM(creatives.body) <> ''"),
                fn (Builder $query): Builder => $query->where(function (Builder $media): void {
                    $media->whereNotNull('creatives.thumbnail_url')
                        ->orWhereNotNull('creatives.image_url');
                }),
            )
            ->select([
                'metrics.account_external_id',
                'metrics.account_name',
                'metrics.meta_ad_id',
                'metrics.meta_campaign_id',
                'metrics.campaign_name',
                'metrics.ad_name',
                'metrics.spend',
                'metrics.purchase_value',
                'metrics.purchases',
                'metrics.impressions',
                'metrics.clicks',
                'creatives.meta_creative_id',
                'creatives.name as creative_name',
                'creatives.title',
                'creatives.body',
                'creatives.image_url',
                'creatives.thumbnail_url',
                'creatives.asset_feed_spec',
                'creatives.object_story_spec',
            ])
            ->orderByRaw('CASE WHEN metrics.spend > 0 THEN metrics.purchase_value / metrics.spend ELSE 0 END DESC')
            ->orderByDesc('metrics.spend')
            ->orderBy('metrics.meta_ad_id')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return [
            'schema' => $content === 'copy' ? 'meta-ads-copy-library-v1' : 'meta-ads-creative-library-v1',
            'filters' => [
                'account' => $account,
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'items' => collect($paginator->items())->map(fn (object $row): array => $this->item($row))->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function metrics(Store $store, CarbonImmutable $from, CarbonImmutable $to, string $account): Builder
    {
        return MetaAdInsight::query()
            ->forOrganization((int) $store->organization_id)
            ->forStore((int) $store->getKey())
            ->where('level', 'ad')
            ->where('granularity', 'day')
            ->whereNotNull('meta_ad_id')
            ->whereDate('date_start', '>=', $from->toDateString())
            ->whereDate('date_start', '<=', $to->toDateString())
            ->when(
                $account !== 'all',
                fn ($query) => $query->whereIn('account_external_id', $this->accountExternalIds($account)),
            )
            ->selectRaw('organization_id, store_id, account_external_id, meta_ad_id')
            ->selectRaw('MAX(account_name) AS account_name')
            ->selectRaw('MAX(meta_campaign_id) AS meta_campaign_id')
            ->selectRaw('MAX(campaign_name) AS campaign_name')
            ->selectRaw('MAX(ad_name) AS ad_name')
            ->selectRaw('COALESCE(SUM(spend), 0) AS spend')
            ->selectRaw('COALESCE(SUM(purchase_value), 0) AS purchase_value')
            ->selectRaw('COALESCE(SUM(purchases), 0) AS purchases')
            ->selectRaw('COALESCE(SUM(impressions), 0) AS impressions')
            ->selectRaw('COALESCE(SUM(clicks), 0) AS clicks')
            ->groupBy('organization_id', 'store_id', 'account_external_id', 'meta_ad_id')
            ->toBase();
    }

    /** @return array<string, float|int|string|null> */
    private function item(object $row): array
    {
        $spend = (float) $row->spend;
        $revenue = (float) $row->purchase_value;
        $impressions = (int) $row->impressions;
        $clicks = (int) $row->clicks;

        return [
            'id' => $row->account_external_id.':'.$row->meta_ad_id,
            'ad_id' => $row->meta_ad_id,
            'creative_id' => $row->meta_creative_id,
            'account_name' => $row->account_name ?: $row->account_external_id,
            'campaign_id' => $row->meta_campaign_id,
            'campaign_name' => $row->campaign_name ?: '未命名广告系列',
            'ad_name' => $row->ad_name ?: '未命名广告',
            'title' => $row->title ?: ($row->creative_name ?: ($row->ad_name ?: '未命名素材')),
            'body' => $row->body,
            'format' => $this->format($row->asset_feed_spec, $row->object_story_spec),
            'image_url' => $row->image_url,
            'thumbnail_url' => $row->thumbnail_url,
            'spend' => round($spend, 2),
            'revenue' => round($revenue, 2),
            'purchases' => round((float) $row->purchases, 2),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'roas' => $spend > 0 ? round($revenue / $spend, 2) : null,
            'ctr' => $impressions > 0 ? round(($clicks / $impressions) * 100, 2) : null,
        ];
    }

    private function format(mixed $assetFeedSpec, mixed $objectStorySpec): string
    {
        $assetFeed = $this->json($assetFeedSpec);
        $objectStory = $this->json($objectStorySpec);

        if (! empty($assetFeed['videos']) || ! empty($objectStory['video_data'])) {
            return '视频素材';
        }
        if (count($assetFeed['images'] ?? []) > 1 || ! empty($objectStory['link_data']['child_attachments'])) {
            return '轮播素材';
        }

        return '图片素材';
    }

    /** @return array<string, mixed> */
    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function date(mixed $value, CarbonImmutable $fallback, string $timezone, string $field): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => '日期格式必须为 YYYY-MM-DD。']);
        }
    }

    /** @return list<string> */
    private function accountExternalIds(string $account): array
    {
        $ids = [$account];
        if (str_starts_with($account, 'act_') && strlen($account) > 4) {
            $ids[] = substr($account, 4);
        } elseif (ctype_digit($account)) {
            $ids[] = 'act_'.$account;
        }

        return array_values(array_unique($ids));
    }
}
