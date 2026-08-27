<?php

namespace App\Exceptions;

use RuntimeException;

class InstagramFeedException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 422,
    ) {
        parent::__construct($message);
    }
}
