<?php

namespace App\Exceptions;

use RuntimeException;

class AdvertisingApiException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus, public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($message);
    }
}
