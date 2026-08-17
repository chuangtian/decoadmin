<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class SensitiveDataProcessor implements ProcessorInterface
{
    private const REDACTED = '[redacted]';

    private const SENSITIVE_KEY_PATTERN = '/(?:access|refresh)[_-]?token|client[_-]?secret|authorization|password|cookie|headers?|payload(?:_encrypted)?|raw[_-]?payload/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $values[$key] = self::REDACTED;

                continue;
            }

            $values[$key] = match (true) {
                is_array($value) => $this->redactArray($value),
                is_string($value) => $this->redactString($value),
                default => $value,
            };
        }

        return $values;
    }

    private function redactString(string $value): string
    {
        $patterns = [
            '/\b(?:access[_-]?token|refresh[_-]?token|client[_-]?secret|authorization|password|cookie)\b\s*[:=]\s*[^\s,;]+/i',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i',
            '/\bshp(?:at|ss|ca|ua)_[A-Za-z0-9]+\b/i',
        ];

        return preg_replace($patterns, self::REDACTED, $value) ?: self::REDACTED;
    }
}
