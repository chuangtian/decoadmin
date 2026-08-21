<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleAdsOAuthException extends RuntimeException
{
    // Messages exposed to the browser must never contain authorization codes or tokens.
}
