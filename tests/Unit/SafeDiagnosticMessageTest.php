<?php

namespace Tests\Unit;

use App\Support\SafeDiagnosticMessage;
use PHPUnit\Framework\TestCase;

class SafeDiagnosticMessageTest extends TestCase
{
    public function test_database_details_are_replaced_with_a_safe_explanation(): void
    {
        $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'secret@example.com' for key 'webhook_events.webhook_id_unique' (Database: decoadmin_staging, SQL: insert into webhook_events ...)";

        $sanitized = SafeDiagnosticMessage::sanitize($message);

        $this->assertSame('数据同步时检测到重复记录冲突，系统会在后续任务中自动重试。', $sanitized);
        $this->assertStringNotContainsString('SQLSTATE', $sanitized);
        $this->assertStringNotContainsString('decoadmin_staging', $sanitized);
        $this->assertStringNotContainsString('secret@example.com', $sanitized);
    }

    public function test_tokens_and_email_addresses_are_redacted_without_hiding_useful_context(): void
    {
        $sanitized = SafeDiagnosticMessage::sanitize('Meta 请求失败，账号 owner@example.com，access_token=secret-token');

        $this->assertStringContainsString('Meta 请求失败', $sanitized);
        $this->assertStringNotContainsString('owner@example.com', $sanitized);
        $this->assertStringNotContainsString('secret-token', $sanitized);
    }
}
