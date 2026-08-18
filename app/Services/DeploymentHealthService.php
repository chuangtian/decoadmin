<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class DeploymentHealthService
{
    /**
     * @return array{status: 'ok'|'degraded', checks: array<string, array{status: 'ok'|'error'}>}
     */
    public function check(): array
    {
        $checks = [
            'application' => ['status' => 'ok'],
            'database' => $this->run(fn () => DB::select('SELECT 1')),
            'redis' => $this->run(fn () => Redis::connection()->ping()),
        ];

        $healthy = collect($checks)->every(
            fn (array $check): bool => $check['status'] === 'ok',
        );

        return [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ];
    }

    /** @return array{status: 'ok'|'error'} */
    private function run(callable $check): array
    {
        try {
            $check();

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'error'];
        }
    }
}
