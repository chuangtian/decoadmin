<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class CurrentYearSyncWindow
{
    public function start(string $timezone): CarbonImmutable
    {
        return CarbonImmutable::now($timezone)->startOfYear();
    }

    public function clampStart(CarbonInterface $candidate, string $timezone): CarbonImmutable
    {
        $value = CarbonImmutable::instance($candidate);
        $floor = $this->start($timezone)->setTimezone($value->getTimezone());

        return $value->lt($floor) ? $floor : $value;
    }

    /** @return array{CarbonImmutable, CarbonImmutable}|null */
    public function clampExistingRange(
        CarbonInterface $from,
        CarbonInterface $to,
        string $timezone,
    ): ?array {
        $rangeFrom = CarbonImmutable::instance($from);
        $rangeTo = CarbonImmutable::instance($to);
        $floor = $this->start($timezone)->setTimezone($rangeFrom->getTimezone());

        if ($rangeTo->lt($floor)) {
            return null;
        }

        return [$rangeFrom->lt($floor) ? $floor : $rangeFrom, $rangeTo];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function clampGeneratedRange(
        CarbonInterface $from,
        CarbonInterface $to,
        string $timezone,
    ): array {
        $rangeFrom = CarbonImmutable::instance($from);
        $rangeTo = CarbonImmutable::instance($to);
        $floor = $this->start($timezone)->setTimezone($rangeFrom->getTimezone());

        return [
            $rangeFrom->lt($floor) ? $floor : $rangeFrom,
            $rangeTo->lt($floor) ? $floor : $rangeTo,
        ];
    }
}
