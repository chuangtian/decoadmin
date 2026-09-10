<?php
namespace DecoMarketing\Services;
use Carbon\CarbonImmutable;

class SendWindow
{
    /** Local 09:00 <= send < 12:00. No location inference from email or IP. */
    public function next(CarbonImmutable $now, string $timezone, string $key): CarbonImmutable
    {
        $local=$now->setTimezone($timezone);
        if($local->hour>=9 && $local->hour<12)return $now;
        $day=$local->startOfDay();
        if($local->hour>=12)$day=$day->addDay();
        $minutes=hexdec(substr(hash('sha256',$key),0,6))%180;
        return $day->setTime(9,0)->addMinutes($minutes)->utc();
    }
}
