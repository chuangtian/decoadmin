<?php

namespace App\Domain\ReferralAffiliate\Enums;

enum MembershipStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Waitlisted = 'waitlisted';
    case Suspended = 'suspended';
    case Left = 'left';
}
