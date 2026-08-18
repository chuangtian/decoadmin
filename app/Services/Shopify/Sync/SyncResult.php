<?php

namespace App\Services\Shopify\Sync;

class SyncResult
{
    /**
     * @param  list<array{code: string, message: string}>  $errors
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly string $message,
        public readonly int $recordsCount,
        public readonly array $errors,
        public readonly array $metadata,
    ) {}

    /** @param array<string, mixed> $metadata */
    public static function successful(string $message, int $recordsCount = 0, array $metadata = []): self
    {
        return new self(true, 'success', $message, $recordsCount, [], $metadata);
    }

    /**
     * @param  list<array{code: string, message: string}>  $errors
     * @param  array<string, mixed>  $metadata
     */
    public static function failed(string $message, array $errors = [], array $metadata = []): self
    {
        return new self(false, 'failed', $message, 0, $errors, $metadata);
    }

    public static function unsupported(string $type): self
    {
        $message = "同步类型 [{$type}] 暂未注册处理器。";

        return new self(false, 'unsupported', $message, 0, [
            ['code' => 'unsupported_sync_type', 'message' => $message],
        ], ['sync_type' => $type, 'framework_only' => true]);
    }

    /** @return array{success: bool, status: string, message: string, records_count: int, errors: list<array{code: string, message: string}>, metadata: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'status' => $this->status,
            'message' => $this->message,
            'records_count' => $this->recordsCount,
            'errors' => $this->errors,
            'metadata' => $this->metadata,
        ];
    }
}
