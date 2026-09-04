<?php

namespace App\Enums;

enum PersonalizationSmartCartCompatibilityStatus: string
{
    case Unchecked = 'unchecked';
    case Compatible = 'compatible';
    case Warning = 'warning';
    case Incompatible = 'incompatible';
}
