<?php

namespace App\Exceptions;

use RuntimeException;

class ShopifyOAuthException extends RuntimeException
{
    // The public message must never contain credentials, codes, or access tokens.
}
