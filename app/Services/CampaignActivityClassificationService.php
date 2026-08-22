<?php

namespace App\Services;

class CampaignActivityClassificationService
{
    public function status(?string $startsOn, ?string $endsOn, string $today): string
    {
        if ($startsOn !== null && $startsOn > $today) {
            return 'upcoming';
        }

        if ($endsOn !== null && $endsOn < $today) {
            return 'completed';
        }

        return 'in_progress';
    }

    public function judgment(?float $roi): string
    {
        if ($roi === null) {
            return 'insufficient_data';
        }

        if ($roi >= 6) {
            return 'reusable';
        }

        if ($roi >= 5) {
            return 'scalable';
        }

        return 'underperforming';
    }
}
