<?php

namespace App\Logging;

use Illuminate\Log\Logger;

class ConfigureSensitiveLogging
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(new SensitiveDataProcessor);
    }
}
