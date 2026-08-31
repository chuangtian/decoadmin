<?php

namespace App\Enums;

enum PersonalizationStrategyVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Superseded = 'superseded';
}
