<?php

namespace App\Enums;

enum PersonalizationComponentStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Disabled = 'disabled';
}
