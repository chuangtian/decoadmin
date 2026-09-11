<?php

namespace App\Domain\ReferralAffiliate\Enums;

enum ProgramStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Archived = 'archived';
}
