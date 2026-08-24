<?php

namespace App\Services\Advertising;

use Carbon\CarbonImmutable;
use Throwable;

class BingConversionGoalClassifier
{
    /**
     * @param  list<array<string, string>>  $rows
     * @return array<string, array{purchase: float, add_to_cart: float, checkout: float, rows: list<array<string, string>>}>
     */
    public function totalsByDate(array $rows): array
    {
        $totals = [];
        foreach ($rows as $row) {
            try {
                $date = CarbonImmutable::parse(trim((string) ($row['TimePeriod'] ?? '')))->toDateString();
            } catch (Throwable) {
                continue;
            }
            $totals[$date] ??= $this->emptyTotals();
            $goal = mb_strtolower(trim((string) ($row['Goal'] ?? '')));
            $value = round(max(0, $this->number($row['AllConversionsQualified'] ?? 0)), 6);
            if (str_contains($goal, '购买成功') || str_contains($goal, 'purchase')) {
                $totals[$date]['purchase'] += $value;
            }
            if (str_contains($goal, 'add to cart') || str_contains($goal, 'addtocart') || str_contains($goal, 'add_to_cart') || str_contains($goal, '加购')) {
                $totals[$date]['add_to_cart'] += $value;
            }
            if (str_contains($goal, 'begin checkout') || str_contains($goal, 'checkout') || str_contains($goal, '发起结账') || str_contains($goal, '结账')) {
                $totals[$date]['checkout'] += $value;
            }
            $totals[$date]['rows'][] = $row;
        }

        return $totals;
    }

    /** @return array{purchase: float, add_to_cart: float, checkout: float, rows: list<array<string, string>>} */
    public function emptyTotals(): array
    {
        return ['purchase' => 0.0, 'add_to_cart' => 0.0, 'checkout' => 0.0, 'rows' => []];
    }

    private function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $normalized = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }
}
