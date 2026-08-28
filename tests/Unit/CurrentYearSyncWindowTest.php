<?php

namespace Tests\Unit;

use App\Support\CurrentYearSyncWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class CurrentYearSyncWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_generated_ranges_are_clamped_to_the_current_year(): void
    {
        CarbonImmutable::setTestNow('2026-08-28 12:00:00 America/Los_Angeles');
        $window = new CurrentYearSyncWindow;

        [$from, $to] = $window->clampGeneratedRange(
            CarbonImmutable::parse('2025-04-01', 'America/Los_Angeles'),
            CarbonImmutable::parse('2026-08-26', 'America/Los_Angeles'),
            'America/Los_Angeles',
        );

        $this->assertSame('2026-01-01', $from->toDateString());
        $this->assertSame('2026-08-26', $to->toDateString());
    }

    public function test_existing_ranges_before_the_current_year_are_skipped(): void
    {
        CarbonImmutable::setTestNow('2026-08-28 12:00:00 UTC');
        $window = new CurrentYearSyncWindow;

        $this->assertNull($window->clampExistingRange(
            CarbonImmutable::parse('2025-01-01', 'UTC'),
            CarbonImmutable::parse('2025-12-31', 'UTC'),
            'UTC',
        ));
    }

    public function test_existing_cross_year_ranges_start_on_january_first(): void
    {
        CarbonImmutable::setTestNow('2026-08-28 12:00:00 UTC');
        $window = new CurrentYearSyncWindow;

        [$from, $to] = $window->clampExistingRange(
            CarbonImmutable::parse('2025-12-01', 'UTC'),
            CarbonImmutable::parse('2026-01-31', 'UTC'),
            'UTC',
        );

        $this->assertSame('2026-01-01', $from->toDateString());
        $this->assertSame('2026-01-31', $to->toDateString());
    }
}
