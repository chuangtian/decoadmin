<?php

namespace App\Console\Commands;

use App\Models\SystemSetting;
use Illuminate\Console\Command;

class ConfigureGeminiStudentRecognition extends Command
{
    protected $signature = 'system:configure-student-ai {--model=gemini-2.5-pro} {--threshold=80}';

    protected $description = 'Persist the platform Gemini student recognition configuration without echoing the API key';

    public function handle(): int
    {
        $key = (string) $this->secret('Gemini API Key');
        if (blank($key)) {
            $this->error('API Key 不能为空。');

            return self::FAILURE;
        }

        $values = [
            'gemini_api_key' => $key,
            'gemini_model' => trim((string) $this->option('model')),
            'auto_approval_threshold' => (float) $this->option('threshold'),
        ];

        foreach ($values as $name => $value) {
            SystemSetting::query()->updateOrCreate(
                ['section' => 'student_ai', 'key' => $name],
                [
                    'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'is_secret' => $name === 'gemini_api_key',
                    'updated_by' => null,
                ],
            );
        }

        $this->info('AI 学生证识别配置已加密保存到当前环境数据库。');

        return self::SUCCESS;
    }
}
