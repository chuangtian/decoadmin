<?php

namespace App\Services\YouTubeAnalytics;

use App\Models\FeishuBitableField;
use App\Models\FeishuBitableRecord;
use App\Models\FeishuBitableTable;
use App\Models\Store;
use App\Services\StoreBusinessCredentialService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class YouTubeAnalyticsSyncService
{
    public const SOURCE_SECTION = 'natural-traffic:social-youtube';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    /** @var list<string> */
    private const FIELDS = [
        '平台', '帖子编号', '账户编号', '账户名称', '标题', '描述', '发布时间', '固定链接',
        '帖子类型', '浏览量', '赞', '评论数', '时长',
    ];

    public function __construct(
        private HttpFactory $http,
        private StoreBusinessCredentialService $credentials,
    ) {}

    /** @return array{tables: int, fields: int, records: int} */
    public function sync(Store $store): array
    {
        $accessToken = $this->accessToken($store);
        $channel = $this->channel($accessToken);
        $channelId = trim((string) ($channel['id'] ?? ''));
        $uploadsId = trim((string) data_get($channel, 'contentDetails.relatedPlaylists.uploads', ''));
        if ($channelId === '' || $uploadsId === '') {
            throw new RuntimeException('YouTube 频道未返回可同步的上传列表。');
        }

        $videoIds = $this->videoIds($accessToken, $uploadsId);
        $videos = $this->videos($accessToken, $videoIds);
        $channelName = trim((string) data_get($channel, 'snippet.title', ''));

        DB::transaction(function () use ($store, $channelId, $channelName, $videos): void {
            $now = now();
            $table = FeishuBitableTable::query()->updateOrCreate(
                [
                    'store_id' => $store->id,
                    'source_section' => self::SOURCE_SECTION,
                    'source_table_id' => 'youtube:'.$channelId,
                ],
                [
                    'organization_id' => $store->organization_id,
                    'name' => 'YouTube 官方 API',
                    'metadata_encrypted' => ['source' => 'official_api', 'platform' => 'YouTube', 'channel_id' => $channelId],
                    'synced_at' => $now,
                ],
            );

            foreach (self::FIELDS as $order => $field) {
                FeishuBitableField::query()->updateOrCreate(
                    ['feishu_bitable_table_id' => $table->id, 'source_field_id' => 'youtube:'.hash('sha256', $field)],
                    [
                        'organization_id' => $store->organization_id,
                        'store_id' => $store->id,
                        'name' => $field,
                        'type' => null,
                        'field_order' => $order,
                        'is_primary' => $field === '帖子编号',
                        'metadata_encrypted' => ['source' => 'official_api'],
                        'synced_at' => $now,
                    ],
                );
            }

            foreach ($videos as $video) {
                $videoId = trim((string) ($video['id'] ?? ''));
                if ($videoId === '') {
                    continue;
                }
                $publishedAt = data_get($video, 'snippet.publishedAt');
                $published = is_string($publishedAt) ? $this->dateTime($publishedAt) : null;
                $fields = [
                    '平台' => 'YouTube',
                    '帖子编号' => $videoId,
                    '账户编号' => $channelId,
                    '账户名称' => $channelName,
                    '标题' => mb_substr((string) data_get($video, 'snippet.title', ''), 0, 2000),
                    '描述' => mb_substr((string) data_get($video, 'snippet.description', ''), 0, 20000),
                    '发布时间' => $published?->toIso8601String() ?? '',
                    '固定链接' => 'https://www.youtube.com/watch?v='.$videoId,
                    '帖子类型' => 'YouTube 视频',
                    '浏览量' => (int) data_get($video, 'statistics.viewCount', 0),
                    '赞' => (int) data_get($video, 'statistics.likeCount', 0),
                    '评论数' => (int) data_get($video, 'statistics.commentCount', 0),
                    '时长' => mb_substr((string) data_get($video, 'contentDetails.duration', ''), 0, 100),
                ];

                FeishuBitableRecord::query()->updateOrCreate(
                    ['feishu_bitable_table_id' => $table->id, 'source_record_id' => 'video:'.$videoId],
                    [
                        'organization_id' => $store->organization_id,
                        'store_id' => $store->id,
                        'fields_encrypted' => $fields,
                        'source_created_at' => $published,
                        'source_updated_at' => $published,
                        'synced_at' => $now,
                    ],
                );
            }

            FeishuBitableTable::query()
                ->forOrganization((int) $store->organization_id)
                ->forStore((int) $store->id)
                ->where('source_section', self::SOURCE_SECTION)
                ->whereKeyNot($table->id)
                ->delete();
        });

        return ['tables' => 1, 'fields' => count(self::FIELDS), 'records' => count($videos)];
    }

    public function isConfigured(Store $store): bool
    {
        return collect(['client_id', 'client_secret', 'refresh_token'])
            ->every(fn (string $key): bool => filled($this->credentials->value($store, 'youtube_analytics', $key)));
    }

    private function accessToken(Store $store): string
    {
        $clientId = trim((string) $this->credentials->value($store, 'youtube_analytics', 'client_id'));
        $clientSecret = trim((string) $this->credentials->value($store, 'youtube_analytics', 'client_secret'));
        $refreshToken = trim((string) $this->credentials->value($store, 'youtube_analytics', 'refresh_token'));
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new RuntimeException('当前店铺尚未完成 YouTube OAuth 授权。');
        }

        $response = $this->http->asForm()->acceptJson()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
        $payload = $response->json();
        $accessToken = is_array($payload) && is_string($payload['access_token'] ?? null) ? trim($payload['access_token']) : '';
        if ($response->failed() || $accessToken === '') {
            throw new RuntimeException('YouTube 授权已失效，请在业务凭据中重新连接。');
        }

        return $accessToken;
    }

    /** @return array<string, mixed> */
    private function channel(string $accessToken): array
    {
        $payload = $this->get($accessToken, '/channels', [
            'part' => 'id,snippet,contentDetails',
            'mine' => 'true',
            'maxResults' => 1,
        ]);
        $channel = data_get($payload, 'items.0');
        if (! is_array($channel)) {
            throw new RuntimeException('YouTube 授权账号没有可访问的频道。');
        }

        return $channel;
    }

    /** @return list<string> */
    private function videoIds(string $accessToken, string $uploadsId): array
    {
        $limit = max(1, min(1000, (int) config('services.youtube_analytics.max_videos', 200)));
        $ids = [];
        $pageToken = null;

        do {
            $query = ['part' => 'contentDetails', 'playlistId' => $uploadsId, 'maxResults' => 50];
            if (is_string($pageToken) && $pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }
            $payload = $this->get($accessToken, '/playlistItems', $query);
            foreach ((array) ($payload['items'] ?? []) as $item) {
                $videoId = trim((string) data_get($item, 'contentDetails.videoId', ''));
                if ($videoId !== '') {
                    $ids[] = $videoId;
                }
                if (count($ids) >= $limit) {
                    break 2;
                }
            }
            $pageToken = is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        } while ($pageToken !== null);

        return array_values(array_unique($ids));
    }

    /** @param list<string> $ids @return list<array<string, mixed>> */
    private function videos(string $accessToken, array $ids): array
    {
        $videos = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            $payload = $this->get($accessToken, '/videos', [
                'part' => 'id,snippet,statistics,contentDetails',
                'id' => implode(',', $chunk),
                'maxResults' => 50,
            ]);
            foreach ((array) ($payload['items'] ?? []) as $video) {
                if (is_array($video)) {
                    $videos[] = $video;
                }
            }
        }

        return $videos;
    }

    /** @param array<string, int|string> $query @return array<string, mixed> */
    private function get(string $accessToken, string $path, array $query): array
    {
        $response = $this->http->withToken($accessToken)->acceptJson()->timeout(30)->get(self::API_URL.$path, $query);
        $payload = $response->json();
        if ($response->failed() || ! is_array($payload)) {
            throw new RuntimeException('YouTube 官方 API 同步失败，请稍后重试或检查授权。');
        }

        return $payload;
    }

    private function dateTime(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
