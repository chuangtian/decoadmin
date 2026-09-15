<?php

namespace Tests\Unit;

use App\Models\Store;
use App\Services\Advertising\GoogleAdsDateRangeService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class GoogleAdsDateRangeTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_date_limits_follow_store_day_instead_of_server_day(): void
    {
        CarbonImmutable::setTestNow('2026-09-15T05:50:00Z');
        $service = app(GoogleAdsDateRangeService::class);
        foreach (['America/Los_Angeles' => '2026-09-14', 'Asia/Shanghai' => '2026-09-15'] as $zone => $day) {
            $store = new Store(['timezone' => $zone]);
            [$from, $to, $today] = $service->resolve($store, []);
            $this->assertSame($day, $today->toDateString());
            $this->assertSame($day, $to->toDateString());
            $this->assertSame($today->subDays(6)->toDateString(), $from->toDateString());
            [$from, $to] = $service->resolve($store, ['date_from' => '2026-09-01', 'date_to' => '2026-09-16']);
            $this->assertSame('2026-09-01', $from->toDateString());
            $this->assertSame($day, $to->toDateString());
        }
    }

    public function test_store_midnight_and_daylight_saving_boundaries(): void
    {
        $store = new Store(['timezone' => 'America/Los_Angeles']);
        foreach ([
            '2026-09-15T06:59:59Z' => '2026-09-14',
            '2026-09-15T07:00:00Z' => '2026-09-15',
            '2026-12-15T07:59:59Z' => '2026-12-14',
            '2026-12-15T08:00:00Z' => '2026-12-15',
        ] as $instant => $day) {
            CarbonImmutable::setTestNow($instant);
            [, $to] = app(GoogleAdsDateRangeService::class)->resolve($store, []);
            $this->assertSame($day, $to->toDateString());
        }
    }
}
