<?php

namespace CommunityReviews;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ReviewException extends RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $status = 503)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['error' => ['code' => $this->errorCode, 'message' => $this->getMessage()]], $this->status);
    }
}
