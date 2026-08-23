<?php

namespace App\Exceptions;

use RuntimeException;

class MetaAdsApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'meta_ads_api_error',
        public readonly ?int $httpStatus = null,
        public readonly ?int $metaErrorCode = null,
    ) {
        parent::__construct($message);
    }
}
