<?php

namespace App\Services\Feishu;

use App\Models\Store;
use Carbon\CarbonImmutable;

class MfDailyReportFormatter
{
    /** @var list<string> */
    private const REQUIRED_NUMERIC_FIELDS = [
        '总销售额', '退款', '总花费', 'ROI（预5%退款）',
        '月销售额总和', '月总花费总和', '月总退款总和', '月ROI', '月度目标销售额', '月每日剩余',
        'GG销售额', 'GG花费', 'GG的ROI',
        'FB销售额-黄智诚', 'FB花费-黄智诚', 'FB的ROI-黄智诚',
        'FB销售额-王静彬', 'FB花费-王静彬', 'FB的ROI-王静彬',
        'Criteo销售', 'Criteo花费', 'Criteo的ROI',
        'Bing销售额', 'Bing花费', 'Bing的ROI',
        'Tiktok销售额', 'Tiktok花费', 'Tiktok的ROI',
    ];

    /** @return array<string, mixed>|null */
    public function report(Store $store, array $fields): ?array
    {
        $date = $this->date($fields['日期'] ?? null);

        if ($date === null) {
            return null;
        }

        $month = CarbonImmutable::parse($date, 'Asia/Shanghai')->format('m');
        $monthlySales = $this->number($fields['月销售额总和'] ?? null);
        $monthlyTarget = $this->number($fields['月度目标销售额'] ?? null);

        return [
            'brand' => $this->brand($store),
            'date' => $date,
            'date_label' => CarbonImmutable::parse($date, 'Asia/Shanghai')->format('Y/m/d'),
            'month' => $month,
            'total_sales' => $this->number($fields['总销售额'] ?? null),
            'refunds' => $this->number($fields['退款'] ?? null),
            'total_spend' => $this->number($fields['总花费'] ?? null),
            'roi' => $this->number($fields['ROI（预5%退款）'] ?? $fields['总ROI'] ?? null),
            'monthly_sales' => $monthlySales,
            'monthly_spend' => $this->number($fields['月总花费总和'] ?? null),
            'monthly_refunds' => $this->number($fields['月总退款总和'] ?? null),
            'monthly_roi' => $this->number($fields['月ROI'] ?? null),
            'monthly_target' => $monthlyTarget,
            'monthly_remaining' => $monthlyTarget !== null && $monthlySales !== null
                ? max(0, $monthlyTarget - $monthlySales)
                : null,
            'daily_required' => $this->number($fields['月每日剩余'] ?? null),
            'channels' => [
                $this->channel('Google', $fields, 'GG销售额', 'GG花费', 'GG的ROI'),
                $this->channel('Meta智诚', $fields, 'FB销售额-黄智诚', 'FB花费-黄智诚', 'FB的ROI-黄智诚'),
                $this->channel('Meta静彬', $fields, 'FB销售额-王静彬', 'FB花费-王静彬', 'FB的ROI-王静彬'),
                $this->channel('Criteo', $fields, 'Criteo销售', 'Criteo花费', 'Criteo的ROI'),
                $this->channel('Bing', $fields, 'Bing销售额', 'Bing花费', 'Bing的ROI'),
                $this->channel('Tiktok', $fields, 'Tiktok销售额', 'Tiktok花费', 'Tiktok的ROI'),
            ],
        ];
    }

    /** @param array<string, mixed> $report */
    public function text(array $report): string
    {
        $lines = [
            $report['brand'].' 每日数据汇报',
            '日期：'.$report['date_label'],
            '总销售额：'.$this->money($report['total_sales']).'（退款：'.$this->money($report['refunds']).'）',
            '总花费：'.$this->money($report['total_spend']),
            'ROI：'.$this->ratio($report['roi']),
            sprintf(
                '截止目前，%s月销售额：%s，%s月总退款：%s，%s月总ROI：%s',
                $report['month'],
                $this->money($report['monthly_sales']),
                $report['month'],
                $this->money($report['monthly_refunds']),
                $report['month'],
                $this->ratio($report['monthly_roi']),
            ),
            sprintf(
                '距离本月%s的目标还差：%s，日均还需：%s',
                $this->compactTarget($report['monthly_target']),
                $this->money($report['monthly_remaining']),
                $this->money($report['daily_required']),
            ),
            '--------------------------------------',
            '',
        ];

        foreach ($report['channels'] as $channel) {
            array_push(
                $lines,
                $channel['label'].':',
                '销售额：'.$this->money($channel['sales']),
                '花费：'.$this->money($channel['spend']),
                'ROI：'.$this->ratio($channel['roi']),
                '',
            );
        }

        $lines[] = '--------------------------------------';

        return implode("\n", $lines);
    }

    public function reportDate(array $fields): ?string
    {
        return $this->date($fields['日期'] ?? null);
    }

    /** @return list<string> */
    public function missingFields(array $fields): array
    {
        $missing = $this->date($fields['日期'] ?? null) === null ? ['日期'] : [];

        foreach (self::REQUIRED_NUMERIC_FIELDS as $field) {
            if ($this->number($fields[$field] ?? null) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /** @return array{label: string, sales: float|null, spend: float|null, roi: float|null} */
    private function channel(string $label, array $fields, string $sales, string $spend, string $roi): array
    {
        return [
            'label' => $label,
            'sales' => $this->number($fields[$sales] ?? null),
            'spend' => $this->number($fields[$spend] ?? null),
            'roi' => $this->number($fields[$roi] ?? null),
        ];
    }

    private function number(mixed $value): ?float
    {
        if (is_array($value)) {
            foreach (['value', 'number', 'text'] as $key) {
                if (array_key_exists($key, $value)) {
                    return $this->number($value[$key]);
                }
            }

            return $value === [] ? null : $this->number(reset($value));
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = str_replace([',', '$', '￥', '¥', ' '], '', trim($value));
        $normalized = rtrim($normalized, '%');

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function date(mixed $value): ?string
    {
        if (is_numeric($value)) {
            $timestamp = (float) $value;
            $seconds = abs($timestamp) >= 100000000000 ? $timestamp / 1000 : $timestamp;

            return CarbonImmutable::createFromTimestampUTC($seconds)
                ->setTimezone('Asia/Shanghai')
                ->toDateString();
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $candidate = is_array($item) ? ($item['text'] ?? $item['value'] ?? null) : $item;
                $date = $this->date($candidate);
                if ($date !== null) {
                    return $date;
                }
            }

            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(str_replace('/', '-', trim($value)), 'Asia/Shanghai')->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function money(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function ratio(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }

    private function compactTarget(mixed $value): string
    {
        $target = (float) ($value ?? 0);

        if ($target >= 10000 && fmod($target, 10000.0) === 0.0) {
            return number_format($target / 10000, 0, '.', '').'万';
        }

        return $this->money($target);
    }

    private function brand(Store $store): string
    {
        $name = trim((string) preg_replace('/\s+Bike$/iu', '', $store->name));

        return $name !== '' ? $name : $store->name;
    }
}
