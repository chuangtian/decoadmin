<?php

namespace App\Exceptions;

use RuntimeException;

class CodexOAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $oauthError,
        string $description,
        public readonly int $status = 400,
    ) {
        parent::__construct($description);
    }
}
