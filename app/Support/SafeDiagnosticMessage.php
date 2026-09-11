<?php

namespace App\Support;

use Illuminate\Support\Str;

final class SafeDiagnosticMessage
{
    public static function sanitize(?string $message, string $fallback = '发生未知异常。', int $limit = 2000): string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return $fallback;
        }

        if (preg_match('/(?:SQLSTATE|integrity constraint violation|\bselect\b.+\bfrom\b|\binsert\s+into\b|\bupdate\b.+\bset\b)/is', $message)) {
            return preg_match('/(?:duplicate entry|unique constraint|SQLSTATE\[23000\]|\b1062\b)/i', $message)
                ? '数据同步时检测到重复记录冲突，系统会在后续任务中自动重试。'
                : '数据同步过程中发生数据库异常，请稍后重试；详细信息仅保留在服务器日志中。';
        }

        $sanitized = preg_replace([
            '/\b(?:access[_-]?token|refresh[_-]?token|client[_-]?secret|password|authorization)\b\s*[:=]\s*[^\s,;]+/i',
            '/\bBearer\s+[^\s,;]+/i',
            '/\bshp(?:at|ss|ca|ua)_[A-Za-z0-9]+\b/i',
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            '/\b(?:database|host|server)\s*[:=]\s*[^\s,;]+/i',
        ], '[redacted]', $message) ?: $fallback;

        return Str::limit($sanitized, max(1, $limit));
    }
}
