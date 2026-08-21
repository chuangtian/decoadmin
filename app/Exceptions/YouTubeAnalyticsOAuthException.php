<?php

namespace App\Exceptions;

use RuntimeException;

class YouTubeAnalyticsOAuthException extends RuntimeException
{
    // Messages exposed to the browser must never contain authorization codes or tokens.
}
