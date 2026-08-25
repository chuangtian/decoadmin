<?php

namespace Tests\Unit;

use App\Services\NaturalTraffic\BrandSocialCsvImportService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BrandSocialCsvFreshnessTest extends TestCase
{
    #[DataProvider('freshnessCases')]
    public function test_freshness_policy_prefers_source_snapshot_and_rejects_ambiguous_regressions(
        array $incoming,
        ?string $incomingSnapshot,
        array $existing,
        ?string $existingSnapshot,
        string $expected,
    ): void {
        $reflection = new ReflectionClass(BrandSocialCsvImportService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('freshnessDecision');

        $result = $method->invoke(
            $service,
            $incoming,
            $incomingSnapshot ? CarbonImmutable::parse($incomingSnapshot) : null,
            CarbonImmutable::parse('2026-08-10 10:00:00'),
            $existing,
            $existingSnapshot ? CarbonImmutable::parse($existingSnapshot) : null,
            CarbonImmutable::parse('2026-08-10 10:00:00'),
        );

        $this->assertSame($expected, $result);
    }

    /** @return array<string, array{array<string, string>, ?string, array<string, string>, ?string, string}> */
    public static function freshnessCases(): array
    {
        return [
            'newer explicit snapshot is authoritative' => [
                ['浏览量' => '90', '赞' => '9'], '2026-08-21 00:00:00',
                ['浏览量' => '100', '赞' => '10'], '2026-08-20 00:00:00',
                'newer',
            ],
            'older explicit snapshot is stale even if uploaded later' => [
                ['浏览量' => '120', '赞' => '12'], '2026-08-19 00:00:00',
                ['浏览量' => '100', '赞' => '10'], '2026-08-20 00:00:00',
                'stale',
            ],
            'missing snapshot may advance monotonic cumulative metrics' => [
                ['浏览量' => '120', '赞' => '12'], null,
                ['浏览量' => '100', '赞' => '10'], null,
                'newer',
            ],
            'missing snapshot never rolls cumulative metrics back' => [
                ['浏览量' => '90', '赞' => '9'], null,
                ['浏览量' => '100', '赞' => '10'], null,
                'stale',
            ],
            'identical snapshot is unchanged' => [
                ['浏览量' => '100', '赞' => '10'], null,
                ['赞' => '10', '浏览量' => '100'], null,
                'unchanged',
            ],
            'mixed metric movement without source time is conservative' => [
                ['浏览量' => '120', '赞' => '9'], null,
                ['浏览量' => '100', '赞' => '10'], null,
                'stale',
            ],
        ];
    }
}
