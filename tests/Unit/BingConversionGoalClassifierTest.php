<?php

namespace Tests\Unit;

use App\Services\Advertising\BingConversionGoalClassifier;
use PHPUnit\Framework\TestCase;

class BingConversionGoalClassifierTest extends TestCase
{
    public function test_it_classifies_qualified_conversions_by_goal_name(): void
    {
        $rows = [
            $this->row('2026-08-20', 'Purchase completed', 10),
            $this->row('2026-08-20', '网站购买成功', 5),
            $this->row('2026-08-20', 'Add to Cart', 50),
            $this->row('2026-08-20', 'addtocart', 30),
            $this->row('2026-08-20', 'add_to_cart', 10),
            $this->row('2026-08-20', '商品加购', 28),
            $this->row('2026-08-20', 'Begin Checkout', 40),
            $this->row('2026-08-20', 'checkout', 20),
            $this->row('2026-08-20', '发起结账', 15),
            $this->row('2026-08-20', '完成结账', 14),
            $this->row('2026-08-20', 'Newsletter signup', 999),
            $this->row('invalid-date', 'purchase', 100),
        ];

        $totals = (new BingConversionGoalClassifier)->totalsByDate($rows);

        $this->assertSame(15.0, $totals['2026-08-20']['purchase']);
        $this->assertSame(118.0, $totals['2026-08-20']['add_to_cart']);
        $this->assertSame(89.0, $totals['2026-08-20']['checkout']);
        $this->assertCount(11, $totals['2026-08-20']['rows']);
        $this->assertArrayNotHasKey('invalid-date', $totals);
    }

    /** @return array<string, string> */
    private function row(string $date, string $goal, int $conversions): array
    {
        return [
            'TimePeriod' => $date,
            'Goal' => $goal,
            'AllConversionsQualified' => (string) $conversions,
        ];
    }
}
