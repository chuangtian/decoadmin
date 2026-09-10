<?php

namespace App\Services\Feishu;

use RuntimeException;

class MfDailyReportImageRenderer
{
    private mixed $image;

    private string $regularFont;

    private string $boldFont;

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>  $history
     */
    public function render(array $report, array $history): string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagettftext')) {
            throw new RuntimeException('服务器 GD 图片组件缺少 FreeType 字体支持。');
        }

        [$this->regularFont, $this->boldFont] = $this->fonts();
        $this->image = imagecreatetruecolor(1690, 900);
        imageantialias($this->image, true);

        $background = $this->color('#111517');
        imagefill($this->image, 0, 0, $background);

        $card = $this->color('#22272a');
        $cardStrong = $this->color('#303638');
        $white = $this->color('#f5f7f8');
        $muted = $this->color('#aeb7bb');
        $gold = $this->color('#f0ad18');
        $green = $this->color('#21d69a');
        $blue = $this->color('#6fb6df');
        $grid = $this->color('#394044');

        $this->box(34, 26, 434, 84, $card);
        $this->text('日期    '.$report['date_label'], 52, 79, 19, $white);
        $this->box(449, 26, 1264, 84, $card);
        $this->center($report['brand'].' 独立站广告数据看板', 856, 80, 28, $gold, true);
        $this->box(1279, 26, 1680, 84, $cardStrong);
        $this->text('◆  广告看板推送', 1304, 79, 20, $white);

        $metrics = [
            ['广告花费', $report['total_spend']],
            ['GMV', $report['total_sales']],
            ['ROI【预扣5%退款】', $report['roi']],
            ['退款', $report['refunds']],
        ];
        foreach ($metrics as $index => [$label, $value]) {
            $x = 34 + ($index * 415);
            $this->box($x, 101, $x + 400, 261, $card);
            $this->text($label, $x + 20, 154, 19, $white);
            $formatted = $index === 2 ? $this->ratio($value) : $this->money($value, true);
            $this->text($formatted, $x + 20, 224, 48, $white, true);
        }

        $this->box(34, 279, 1264, 346, $card);
        $this->center('本月数据概览', 650, 330, 30, $white, true);
        $this->box(1279, 279, 1680, 510, $card);
        $this->text('月目标完成度', 1300, 329, 17, $white);
        $rate = (float) ($report['monthly_target'] ?? 0) > 0
            ? min(1, max(0, (float) $report['monthly_sales'] / (float) $report['monthly_target']))
            : 0;
        $this->progressRing(1480, 400, 66, $rate, $grid, $green);
        $this->center(number_format($rate * 100, 0).'%', 1480, 417, 27, $white, true);
        $this->center(
            '$'.$this->money($report['monthly_sales'], true).' / $'.$this->money($report['monthly_target'], true),
            1480,
            471,
            15,
            $muted,
        );

        $monthly = [
            ['本月销售额', $report['monthly_sales'], 'money'],
            ['本月广告花费', $report['monthly_spend'] ?? null, 'money'],
            ['本月ROI', $report['monthly_roi'], 'ratio'],
            ['本月退款', $report['monthly_refunds'], 'money'],
            ['日均还需', $report['daily_required'], 'money'],
        ];
        foreach ($monthly as $index => [$label, $value, $format]) {
            $x = 34 + ($index * 246);
            $this->box($x, 361, $x + 231, 510, $card);
            $this->text($label, $x + 17, 410, 16, $white);
            $this->text(
                $format === 'ratio' ? $this->ratio($value) : $this->money($value, true),
                $x + 17,
                464,
                25,
                $white,
                true,
            );
        }

        $this->box(34, 528, 1680, 876, $card);
        $this->text('最近30天销售额、花费与ROI', 54, 572, 17, $white);
        $this->chart($history, 65, 605, 1585, 220, $green, $blue, $gold, $grid, $muted);

        ob_start();
        imagepng($this->image, null, 7);
        $contents = ob_get_clean();
        imagedestroy($this->image);

        if (! is_string($contents) || ! str_starts_with($contents, "\x89PNG")) {
            throw new RuntimeException('生成每日数据看板图片失败。');
        }

        return $contents;
    }

    /** @param list<array<string, mixed>> $history */
    private function chart(array $history, int $x, int $y, int $width, int $height, int $green, int $blue, int $gold, int $grid, int $muted): void
    {
        $history = array_slice($history, -30);
        if ($history === []) {
            $this->center('暂无趋势数据', $x + intdiv($width, 2), $y + intdiv($height, 2), 18, $muted);

            return;
        }

        $maxMoney = max(1, ...array_map(
            fn (array $row): float => max((float) ($row['total_sales'] ?? 0), (float) ($row['total_spend'] ?? 0)),
            $history,
        ));
        $maxRoi = max(1, ...array_map(fn (array $row): float => (float) ($row['roi'] ?? 0), $history));

        for ($line = 0; $line <= 4; $line++) {
            $lineY = $y + (int) round(($height / 4) * $line);
            imageline($this->image, $x, $lineY, $x + $width, $lineY, $grid);
        }

        $count = count($history);
        $slot = $width / $count;
        $barWidth = max(4, min(16, (int) floor($slot * .28)));
        $previous = null;

        foreach ($history as $index => $row) {
            $centerX = (int) round($x + ($slot * $index) + ($slot / 2));
            $salesHeight = (int) round(((float) ($row['total_sales'] ?? 0) / $maxMoney) * ($height - 30));
            $spendHeight = (int) round(((float) ($row['total_spend'] ?? 0) / $maxMoney) * ($height - 30));
            imagefilledrectangle($this->image, $centerX - $barWidth - 2, $y + $height - $salesHeight, $centerX - 2, $y + $height, $green);
            imagefilledrectangle($this->image, $centerX + 2, $y + $height - $spendHeight, $centerX + $barWidth + 2, $y + $height, $blue);

            $roiY = $y + $height - (int) round(((float) ($row['roi'] ?? 0) / $maxRoi) * ($height - 30));
            if ($previous !== null) {
                imageline($this->image, $previous[0], $previous[1], $centerX, $roiY, $gold);
                imageline($this->image, $previous[0], $previous[1] + 1, $centerX, $roiY + 1, $gold);
            }
            imagefilledellipse($this->image, $centerX, $roiY, 7, 7, $gold);
            $previous = [$centerX, $roiY];

            if ($index % max(1, (int) ceil($count / 10)) === 0 || $index === $count - 1) {
                $label = substr((string) ($row['date'] ?? ''), 5);
                $this->center($label, $centerX, $y + $height + 25, 10, $muted);
            }
        }
    }

    private function progressRing(int $x, int $y, int $radius, float $rate, int $track, int $fill): void
    {
        imagesetthickness($this->image, 13);
        imagearc($this->image, $x, $y, $radius * 2, $radius * 2, 135, 405, $track);
        if ($rate > 0) {
            imagearc($this->image, $x, $y, $radius * 2, $radius * 2, 135, 135 + (int) round(270 * $rate), $fill);
        }
        imagesetthickness($this->image, 1);
    }

    private function box(int $x1, int $y1, int $x2, int $y2, int $color): void
    {
        imagefilledrectangle($this->image, $x1, $y1, $x2, $y2, $color);
    }

    private function text(string $text, int $x, int $baseline, int $size, int $color, bool $bold = false): void
    {
        imagettftext($this->image, $size, 0, $x, $baseline, $color, $bold ? $this->boldFont : $this->regularFont, $text);
    }

    private function center(string $text, int $centerX, int $baseline, int $size, int $color, bool $bold = false): void
    {
        $font = $bold ? $this->boldFont : $this->regularFont;
        $box = imagettfbbox($size, 0, $font, $text);
        $width = is_array($box) ? abs($box[2] - $box[0]) : 0;
        $this->text($text, $centerX - intdiv($width, 2), $baseline, $size, $color, $bold);
    }

    private function color(string $hex): int
    {
        $hex = ltrim($hex, '#');

        return imagecolorallocate(
            $this->image,
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        );
    }

    /** @return array{string, string} */
    private function fonts(): array
    {
        $regular = (string) config('services.feishu_table.daily_report_font_regular', '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc');
        $bold = (string) config('services.feishu_table.daily_report_font_bold', '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc');

        if (! is_file($regular) || ! is_readable($regular)) {
            throw new RuntimeException('每日数据看板缺少可读的中文常规字体。');
        }
        if (! is_file($bold) || ! is_readable($bold)) {
            $bold = $regular;
        }

        return [$regular, $bold];
    }

    private function money(mixed $value, bool $grouped = false): string
    {
        return number_format((float) ($value ?? 0), 2, '.', $grouped ? ',' : '');
    }

    private function ratio(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
