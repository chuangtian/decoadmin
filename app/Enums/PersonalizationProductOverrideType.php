<?php

namespace App\Enums;

enum PersonalizationProductOverrideType: string
{
    case Manual = 'manual';
    case Pinned = 'pinned';
    case Excluded = 'excluded';
}
