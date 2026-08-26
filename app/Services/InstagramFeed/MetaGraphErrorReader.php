<?php

namespace App\Services\InstagramFeed;

use Illuminate\Http\Client\Response;

/**
 * Meta Graph API 的错误提取。
 *
 * 错误正文里可能带 access token 之类的敏感串，所以只取结构化的 message 字段，
 * 并做长度截断；解析不出来时一律返回空字符串，绝不回传原始正文。
 */
class MetaGraphErrorReader
{
    private const MAX_LENGTH = 200;

    public static function read(Response $response): string
    {
        foreach (['error.message', 'error_message', 'error_description', 'error.error_user_msg'] as $path) {
            $message = $response->json($path);
            if (is_string($message) && trim($message) !== '') {
                return mb_substr(trim($message), 0, self::MAX_LENGTH);
            }
        }

        return 'HTTP '.$response->status();
    }

    public static function suffix(Response $response): string
    {
        $message = self::read($response);

        return $message === '' ? '' : '（'.$message.'）';
    }
}
