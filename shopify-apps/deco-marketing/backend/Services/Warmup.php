<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use DecoMarketing\Models\Settings;

class Warmup
{
    public function report(Store $store): array
    {
        app(Guard::class)->store($store);
        $settings = Settings::forStore($store)->first();
        $steps = $settings?->warmup_steps ?: [300, 800, 5000];
        $week = $settings?->cutover_at && $settings->cutover_at->isPast()
            ? (int) floor($settings->cutover_at->diffInDays(now()) / 7) + 1 : 0;

        return ['enabled' => (bool) $settings?->warmup_enabled, 'week' => $week, 'current' => $settings?->daily_limit ?? 0,
            'target' => $week ? $steps[min($week - 1, count($steps) - 1)] : 0,
            'steps' => $steps, 'goal' => end($steps), 'capacity' => 50000];
    }

    public function advance(Store $store): void
    {
        $report = $this->report($store);
        if (! $report['enabled'] || ! $report['target'] || $report['current'] >= $report['target']) {
            return;
        }
        // Existing safety failures block increases. A read of the report never changes settings.
        $health = app(Health::class)->report($store);
        if ($health['circuit_open'] || ($health['failure_rate'] ?? 0) >= 2 || ($health['unsubscribe_rate'] ?? 0) >= 0.5) {
            return;
        }
        Settings::forStore($store)->where('warmup_enabled', true)->where('daily_limit', '<', $report['target'])->update(['daily_limit' => $report['target']]);
    }
}
