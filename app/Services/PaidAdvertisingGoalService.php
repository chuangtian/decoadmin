<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PaidAdvertisingGoalBoard;
use App\Models\PaidAdvertisingGoalField;
use App\Models\PaidAdvertisingGoalRecord;
use App\Models\PaidAdvertisingGoalSyncRun;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

class PaidAdvertisingGoalService
{
    public const TYPE_PERSONAL_FACEBOOK = 'personal_facebook';

    public const TYPE_GOOGLE_ADS = 'google_ads';

    public const TYPES = [self::TYPE_PERSONAL_FACEBOOK, self::TYPE_GOOGLE_ADS];

    private const FEISHU_SECTION = 'advertising_goals';

    private const MAX_BOARDS_PER_STORE = 50;

    public function __construct(
        private StoreFeishuDataLinkService $dataLinks,
        private PaidAdvertisingGoalMetricsService $metrics,
        private PaidAdvertisingPersonalFacebookMetricsService $personalFacebookMetrics,
        private PaidAdvertisingGoogleAdsMetricsService $googleAdsMetrics,
    ) {}

    /**
     * @return array{
     *     schema: string,
     *     active_tab: string,
     *     tabs: list<array{key: string, label: string, kind: string, board_id: int|null, type: string|null, deletable: bool, clearable: bool}>,
     *     type_options: list<array{value: string, label: string}>,
     *     configuration: array{schema: string, section: string, configured: bool, has_configuration: bool, missing_fields: list<string>},
     *     period: array{schema: string, mode: string, month: string|null, date_from: string, date_to: string, label: string, timezone: string},
     *     metrics: array<string, mixed>,
     *     template_data: array{personal_facebook: array<string, mixed>|null, google_ads: array<string, mixed>|null}
     * }
     */
    public function page(
        Organization $organization,
        Store $store,
        ?string $requestedTab = null,
        array $periodFilters = [],
    ): array {
        $this->assertScope($organization, $store);
        $boards = PaidAdvertisingGoalBoard::query()
            ->forOrganization($organization)
            ->forStore($store)
            ->select(['id', 'organization_id', 'store_id', 'name', 'type'])
            ->orderBy('id')
            ->limit(self::MAX_BOARDS_PER_STORE)
            ->get();
        $configuration = $this->dataLinks->sectionStatusForFrontend($store, self::FEISHU_SECTION);
        $tabs = [[
            'key' => 'overall',
            'label' => '总目标',
            'kind' => 'overall',
            'board_id' => null,
            'type' => null,
            'deletable' => false,
            'clearable' => $configuration['has_configuration'],
        ], ...$boards->map(fn (PaidAdvertisingGoalBoard $board): array => [
            'key' => $this->tabKey($board),
            'label' => $board->name,
            'kind' => 'board',
            'board_id' => (int) $board->id,
            'type' => $board->type,
            'deletable' => true,
            'clearable' => false,
        ])->all()];
        $activeTab = collect($tabs)->contains(fn (array $tab): bool => $tab['key'] === $requestedTab)
            ? (string) $requestedTab
            : 'overall';
        $activeTabDefinition = collect($tabs)->firstWhere('key', $activeTab);
        $activeBoard = $activeTabDefinition['board_id'] === null
            ? null
            : $boards->firstWhere('id', $activeTabDefinition['board_id']);
        $period = $this->period($store, $periodFilters);

        return [
            'schema' => 'paid-advertising-goals-v1',
            'active_tab' => $activeTab,
            'tabs' => $tabs,
            'type_options' => [
                ['value' => self::TYPE_PERSONAL_FACEBOOK, 'label' => '个人目标看板＋Facebook数据'],
                ['value' => self::TYPE_GOOGLE_ADS, 'label' => 'Google广告目标'],
            ],
            'configuration' => $configuration,
            'period' => $period,
            'metrics' => $this->metrics->summary(
                $organization,
                $store,
                $activeTabDefinition['board_id'],
                $period,
            ),
            'template_data' => [
                'personal_facebook' => $activeBoard instanceof PaidAdvertisingGoalBoard
                    && $activeBoard->type === self::TYPE_PERSONAL_FACEBOOK
                        ? $this->personalFacebookMetrics->summary($organization, $store, $activeBoard, $period)
                        : null,
                'google_ads' => $activeBoard instanceof PaidAdvertisingGoalBoard
                    && $activeBoard->type === self::TYPE_GOOGLE_ADS
                        ? $this->googleAdsMetrics->summary($organization, $store, $activeBoard, $period)
                        : null,
            ],
        ];
    }

    /** @param array<string, mixed> $values */
    public function configureOverall(Organization $organization, Store $store, User $actor, array $values): void
    {
        $this->assertScope($organization, $store);
        $this->dataLinks->updateSection($store, self::FEISHU_SECTION, [
            'advertising_goals_app_token' => $values['feishu_app_token'],
            'advertising_goals_table_id' => $values['feishu_table_id'],
            'advertising_goals_view_id' => $values['feishu_view_id'],
        ], $actor);
    }

    public function clearOverall(Organization $organization, Store $store, User $actor): void
    {
        $this->assertScope($organization, $store);

        DB::transaction(function () use ($organization, $store, $actor): void {
            $recordCount = PaidAdvertisingGoalRecord::query()
                ->forOrganization($organization)
                ->forStore($store)
                ->where('source_key', 'overall')
                ->delete();
            $fieldCount = PaidAdvertisingGoalField::query()
                ->forOrganization($organization)
                ->forStore($store)
                ->where('source_key', PaidAdvertisingGoalRefreshService::TARGET_OVERALL)
                ->delete();
            $syncRunCount = PaidAdvertisingGoalSyncRun::query()
                ->forOrganization($organization)
                ->forStore($store)
                ->where('target_key', PaidAdvertisingGoalRefreshService::TARGET_OVERALL)
                ->delete();
            $credentialCount = $this->dataLinks->clearSection($store, self::FEISHU_SECTION, $actor);

            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'user_id' => $actor->id,
                'action' => 'paid_advertising_overall_goal_cleared',
                'subject_type' => Store::class,
                'subject_id' => $store->id,
                'old_values' => [
                    'configured' => $credentialCount > 0,
                    'records_deleted' => $recordCount,
                    'fields_deleted' => $fieldCount,
                    'sync_runs_deleted' => $syncRunCount,
                    'credentials_deleted' => $credentialCount,
                ],
                'new_values' => ['configured' => false],
                'metadata' => [
                    'scope' => 'store',
                    'daily_sync_removed' => true,
                    'fixed_tab_preserved' => true,
                ],
            ]);
        });
    }

    /** @param array<string, mixed> $values */
    public function create(Organization $organization, Store $store, User $actor, array $values): PaidAdvertisingGoalBoard
    {
        $this->assertScope($organization, $store);

        if (PaidAdvertisingGoalBoard::query()->forOrganization($organization)->forStore($store)->count() >= self::MAX_BOARDS_PER_STORE) {
            throw ValidationException::withMessages([
                'name' => '每个店铺最多可添加 '.self::MAX_BOARDS_PER_STORE.' 个目标页签。',
            ]);
        }

        return DB::transaction(function () use ($organization, $store, $actor, $values): PaidAdvertisingGoalBoard {
            $isGoogleAds = $values['type'] === self::TYPE_GOOGLE_ADS;
            $board = PaidAdvertisingGoalBoard::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'name' => $values['name'],
                'type' => $values['type'],
                'feishu_app_token' => $values['feishu_app_token'],
                'feishu_table_id' => $isGoogleAds ? '' : $values['feishu_table_id'],
                'feishu_view_id' => $isGoogleAds ? '' : $values['feishu_view_id'],
                'sync_status' => 'pending',
                'created_by' => $actor->id,
            ]);

            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'user_id' => $actor->id,
                'action' => 'paid_advertising_goal_board_created',
                'subject_type' => PaidAdvertisingGoalBoard::class,
                'subject_id' => $board->id,
                'new_values' => [
                    'name' => $board->name,
                    'type' => $board->type,
                    'configured' => true,
                ],
                'metadata' => [
                    'scope' => 'store',
                    'sync_schedule' => 'daily',
                ],
            ]);

            return $board;
        });
    }

    public function delete(Organization $organization, Store $store, User $actor, PaidAdvertisingGoalBoard $board): void
    {
        $this->assertScope($organization, $store);
        abort_unless(
            (int) $board->organization_id === (int) $organization->id
            && (int) $board->store_id === (int) $store->id,
            404,
        );

        DB::transaction(function () use ($organization, $store, $actor, $board): void {
            $boardId = (int) $board->id;
            $name = $board->name;
            $type = $board->type;
            $recordCount = $board->records()->count();
            $fieldCount = $board->fields()->count();
            $syncRunCount = PaidAdvertisingGoalSyncRun::query()
                ->where('goal_board_id', $boardId)
                ->count();

            $board->delete();

            AuditLog::query()->create([
                'organization_id' => $organization->id,
                'store_id' => $store->id,
                'user_id' => $actor->id,
                'action' => 'paid_advertising_goal_board_deleted',
                'subject_type' => PaidAdvertisingGoalBoard::class,
                'subject_id' => $boardId,
                'old_values' => [
                    'name' => $name,
                    'type' => $type,
                    'configured' => true,
                    'records_deleted' => $recordCount,
                    'fields_deleted' => $fieldCount,
                    'sync_runs_deleted' => $syncRunCount,
                ],
                'new_values' => ['deleted' => true],
                'metadata' => [
                    'scope' => 'store',
                    'daily_sync_removed' => true,
                ],
            ]);
        });
    }

    public function tabKey(PaidAdvertisingGoalBoard $board): string
    {
        return 'board-'.(int) $board->id;
    }

    private function assertScope(Organization $organization, Store $store): void
    {
        if ((int) $store->organization_id !== (int) $organization->id) {
            throw new InvalidArgumentException('The store does not belong to the supplied organization.');
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{schema: string, mode: string, month: string|null, date_from: string, date_to: string, label: string, timezone: string}
     */
    private function period(Store $store, array $filters): array
    {
        $timezone = $store->timezone ?: (string) config('app.timezone', 'UTC');
        $currentMonth = CarbonImmutable::now($timezone)->startOfMonth();

        if (($filters['period_mode'] ?? null) === 'range') {
            $from = $this->parseDate($filters['date_from'] ?? null, $timezone);
            $to = $this->parseDate($filters['date_to'] ?? null, $timezone);

            if ($from && $to && $from->lessThanOrEqualTo($to) && $from->diffInDays($to) <= 365) {
                return [
                    'schema' => 'paid-advertising-goal-period-v1',
                    'mode' => 'range',
                    'month' => null,
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                    'label' => $from->format('Y-m-d').' 至 '.$to->format('Y-m-d'),
                    'timezone' => $timezone,
                ];
            }
        }

        $month = $this->parseMonth($filters['month'] ?? null, $timezone) ?? $currentMonth;

        return [
            'schema' => 'paid-advertising-goal-period-v1',
            'mode' => 'month',
            'month' => $month->format('Y-m'),
            'date_from' => $month->startOfMonth()->toDateString(),
            'date_to' => $month->endOfMonth()->toDateString(),
            'label' => $month->format('Y').'年'.(int) $month->format('m').'月',
            'timezone' => $timezone,
        ];
    }

    private function parseMonth(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value) !== 1) {
            return null;
        }

        try {
            $month = CarbonImmutable::createFromFormat('!Y-m', $value, $timezone);

            return $month->format('Y-m') === $value ? $month : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function parseDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-(0[1-9]|1[0-2])-([0-2]\d|3[01])$/D', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            return $date->format('Y-m-d') === $value ? $date : null;
        } catch (Throwable) {
            return null;
        }
    }
}
