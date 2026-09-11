<?php

namespace App\Domain\ReferralAffiliate\Enums;

enum ProgramType: string
{
    case Affiliate = 'affiliate';
    case Influencer = 'influencer';
    case Ambassador = 'ambassador';
    case Advocate = 'advocate';
    case Partner = 'partner';
}
