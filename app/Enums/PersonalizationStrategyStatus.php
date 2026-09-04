<?php

namespace App\Enums;

enum PersonalizationStrategyStatus: string
{
    case Draft = 'draft';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case ConfigurationError = 'configuration_error';
    case Archived = 'archived';
}
