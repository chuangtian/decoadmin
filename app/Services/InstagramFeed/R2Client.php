<?php

namespace App\Services\InstagramFeed;

use App\Exceptions\InstagramFeedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Cloudflare R2 存储层。转存后的视频与封面放这里，对外走绑定在桶上的自定义域名，
 * 地址永久有效。
 * https://developers.cloudflare.com/r2/api/s3/api/
 *
 * 只需要 PUT / DELETE 两个操作，所以直接手写 AWS SigV4 签名走 HTTP 客户端，
 * 不引入 aws-sdk 及其传递依赖。
 *
 * 文件一律用临时文件中转（下载用 sink、上传用 stream），不把整个视频读进内存。
 */
class R2Client
{
    private const SERVICE = 's3';

    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(private HttpFactory $http) {}

    public function isConfigured(): bool
    {
        foreach (['account_id', 'access_key_id', 'secret_access_key', 'bucket', 'public_base_url'] as $key) {
            if (blank(config('instagram_feed.r2.'.$key))) {
                return false;
            }
        }

        return true;
    }

    /** 对外访问地址，走绑定在桶上的自定义域名。 */
    public function publicUrl(string $key): string
    {
        return $this->config()['public_base_url'].'/'.$this->encodeKey($key);
    }

    /**
     * 把 Instagram CDN 上的文件搬到 R2。
     *
     * Instagram 的地址带签名会过期，所以必须在拿到地址后尽快搬。
     *
     * @return array{bytes: int, content_type: string}
     */
    public function copyFromUrl(string $sourceUrl, string $key, string $fallbackContentType): array
    {
        $maxBytes = (int) config('instagram_feed.mirror.max_object_bytes', 200 * 1024 * 1024);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'igmirror_');
        if ($temporaryPath === false) {
            throw new InstagramFeedException('MIRROR_TEMP_FILE_FAILED', '无法创建转存临时文件。', 500);
        }

        try {
            try {
                $response = $this->http
                    ->connectTimeout(10)
                    ->timeout(180)
                    ->sink($temporaryPath)
                    ->get($sourceUrl);
            } catch (ConnectionException) {
                throw new InstagramFeedException('MIRROR_SOURCE_TIMEOUT', '拉取 Instagram 文件超时。', 502);
            }
            if ($response->failed()) {
                throw new InstagramFeedException(
                    'MIRROR_SOURCE_FETCH_FAILED',
                    '拉取 Instagram 文件失败（HTTP '.$response->status().'）。',
                    502,
                );
            }

            $bytes = (int) (filesize($temporaryPath) ?: 0);
            if ($bytes <= 0) {
                throw new InstagramFeedException('MIRROR_SOURCE_EMPTY', 'Instagram 返回了空文件。', 502);
            }
            if ($bytes > $maxBytes) {
                throw new InstagramFeedException(
                    'MIRROR_SOURCE_TOO_LARGE',
                    '文件过大（'.(int) round($bytes / 1048576).'MB），已跳过。',
                    422,
                );
            }

            $contentType = $this->normalizeContentType($response->header('Content-Type')) ?? $fallbackContentType;
            $this->putFile($key, $temporaryPath, $contentType);

            return ['bytes' => $bytes, 'content_type' => $contentType];
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function putFile(string $key, string $path, string $contentType): void
    {
        $payloadHash = hash_file('sha256', $path);
        if (! is_string($payloadHash)) {
            throw new InstagramFeedException('MIRROR_UPLOAD_FAILED', '无法计算转存文件摘要。', 500);
        }

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new InstagramFeedException('MIRROR_UPLOAD_FAILED', '无法读取转存临时文件。', 500);
        }

        try {
            $headers = [
                'Content-Type' => $contentType,
                // key 里带了 Instagram 的媒体 ID，同一个 ID 内容不会变，可以长缓存。
                'Cache-Control' => (string) config('instagram_feed.mirror.cache_control', 'public, max-age=31536000, immutable'),
            ];
            $response = $this->signedRequest('PUT', $key, $payloadHash, $headers, $stream, $contentType);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($response->failed()) {
            throw new InstagramFeedException(
                'MIRROR_UPLOAD_FAILED',
                '上传到 R2 失败：'.$this->readError($response),
                502,
            );
        }
    }

    public function deleteObject(string $key): void
    {
        // 对象本来就不存在时 R2 也返回 204，只需处理真正的失败。
        $response = $this->signedRequest('DELETE', $key, hash('sha256', ''), [], null, null);
        if ($response->failed() && $response->status() !== 404) {
            throw new InstagramFeedException(
                'MIRROR_DELETE_FAILED',
                '从 R2 删除失败：'.$this->readError($response),
                502,
            );
        }
    }

    /**
     * 批量删除。单个失败不中断其余的，返回失败的 key 供上层记录。
     *
     * 清理场景（断开授权、卸载应用、数据删除请求）不能因为一个对象删不掉就整体回滚。
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function deleteObjects(array $keys): array
    {
        $failed = [];
        foreach (array_unique($keys) as $key) {
            try {
                $this->deleteObject($key);
            } catch (Throwable) {
                $failed[] = $key;
            }
        }

        return array_values($failed);
    }

    /**
     * @param  array<string, string>  $extraHeaders
     * @param  resource|null  $body
     */
    private function signedRequest(
        string $method,
        string $key,
        string $payloadHash,
        array $extraHeaders,
        mixed $body,
        ?string $contentType,
    ): Response {
        $config = $this->config();
        $host = $config['account_id'].'.r2.cloudflarestorage.com';
        $canonicalUri = '/'.rawurlencode($config['bucket']).'/'.$this->encodeKey($key);
        $timestamp = time();
        $amzDate = gmdate('Ymd\THis\Z', $timestamp);
        $dateStamp = gmdate('Ymd', $timestamp);

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        foreach ($extraHeaders as $name => $value) {
            $headers[strtolower($name)] = trim($value);
        }
        ksort($headers, SORT_STRING);

        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.preg_replace('/\s+/', ' ', $value)."\n";
        }
        $signedHeaders = implode(';', array_keys($headers));

        $canonicalRequest = implode("\n", [
            $method,
            $canonicalUri,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);
        $credentialScope = $dateStamp.'/'.$config['region'].'/'.self::SERVICE.'/aws4_request';
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $credentialScope,
            hash('sha256', $canonicalRequest),
        ]);

        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', self::SERVICE, hash_hmac(
            'sha256',
            $config['region'],
            hash_hmac('sha256', $dateStamp, 'AWS4'.$config['secret_access_key'], true),
            true,
        ), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $requestHeaders = $headers;
        unset($requestHeaders['host']);
        $requestHeaders['Authorization'] = self::ALGORITHM
            .' Credential='.$config['access_key_id'].'/'.$credentialScope
            .', SignedHeaders='.$signedHeaders
            .', Signature='.$signature;

        $request = $this->http
            ->withHeaders($requestHeaders)
            ->connectTimeout(10)
            ->timeout(180);
        if ($body !== null) {
            $request = $request->withBody($body, $contentType ?? 'application/octet-stream');
        }

        try {
            return $request->send($method, 'https://'.$host.$canonicalUri);
        } catch (ConnectionException) {
            throw new InstagramFeedException('R2_REQUEST_TIMEOUT', 'Cloudflare R2 请求超时。', 502);
        }
    }

    /** @return array{account_id: string, access_key_id: string, secret_access_key: string, bucket: string, public_base_url: string, region: string} */
    private function config(): array
    {
        if (! $this->isConfigured()) {
            throw new InstagramFeedException(
                'R2_NOT_CONFIGURED',
                'Cloudflare R2 尚未配置，无法转存 Instagram 媒体。',
                503,
            );
        }

        return [
            'account_id' => trim((string) config('instagram_feed.r2.account_id')),
            'access_key_id' => trim((string) config('instagram_feed.r2.access_key_id')),
            'secret_access_key' => (string) config('instagram_feed.r2.secret_access_key'),
            'bucket' => trim((string) config('instagram_feed.r2.bucket')),
            'public_base_url' => rtrim((string) config('instagram_feed.r2.public_base_url'), '/'),
            'region' => (string) config('instagram_feed.r2.region', 'auto'),
        ];
    }

    /** key 里的每一段都要单独编码，斜杠本身要保留。 */
    private function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    }

    private function normalizeContentType(?string $header): ?string
    {
        if (! is_string($header) || trim($header) === '') {
            return null;
        }
        $value = trim(explode(';', $header)[0]);

        return preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#i', $value) === 1 ? strtolower($value) : null;
    }

    /** R2 返回 XML，把 Message 抠出来就够定位问题了。 */
    private function readError(Response $response): string
    {
        $body = $response->body();
        if (preg_match('#<Message>([^<]{1,200})</Message>#', $body, $matches) === 1) {
            return $matches[1];
        }

        return 'HTTP '.$response->status();
    }
}
