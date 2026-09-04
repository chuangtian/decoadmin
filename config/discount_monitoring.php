<?php

return [
    'enabled' => (bool) env('DISCOUNT_MONITORING_ENABLED', true),
    'poll_minutes' => min(10, max(5, (int) env('DISCOUNT_MONITORING_POLL_MINUTES', 5))),
    'thresholds' => [
        '7d' => 7 * 24 * 60 * 60,
        '3d' => 3 * 24 * 60 * 60,
        '24h' => 24 * 60 * 60,
    ],
];
