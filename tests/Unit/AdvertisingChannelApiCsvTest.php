<?php

namespace Tests\Unit;

use App\Services\Advertising\AdvertisingChannelApiService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class AdvertisingChannelApiCsvTest extends TestCase
{
    #[DataProvider('bomHeaders')]
    public function test_bing_csv_parser_normalizes_the_first_header(string $header): void
    {
        $csv = $header.",AccountId,Spend,Revenue\n2026-08-17,187016548,2055.82,15265.22";
        $reflection = new ReflectionClass(AdvertisingChannelApiService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('csv');

        $rows = $method->invoke($service, $csv, ['Spend', 'Revenue']);

        $this->assertSame('2026-08-17', $rows[0]['TimePeriod']);
        $this->assertSame('2055.82', $rows[0]['Spend']);
        $this->assertArrayNotHasKey('\u{FEFF}"TimePeriod"', $rows[0]);
    }

    /** @return array<string, array{string}> */
    public static function bomHeaders(): array
    {
        return [
            'utf8 bom before quoted header' => ["\xEF\xBB\xBF\"TimePeriod\""],
            'plain quoted header' => ['"TimePeriod"'],
        ];
    }
}
