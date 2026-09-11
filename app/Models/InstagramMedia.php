<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'uuid', 'organization_id', 'store_id', 'account_id', 'ig_media_id', 'media_type',
    'media_product_type', 'caption', 'permalink', 'ig_media_url', 'ig_thumbnail_url',
    'posted_at', 'like_count', 'comments_count', 'mirror_status', 'video_key', 'poster_key',
    'video_url', 'poster_url', 'width', 'height', 'duration_ms', 'mirror_error', 'mirrored_at',
    'product_ids',
])]
// IG CDN 地址只用于转存，签名会过期，不应外泄到前端。
#[Hidden(['ig_media_url', 'ig_thumbnail_url', 'video_key', 'poster_key'])]
class InstagramMedia extends Model
{
    protected $table = 'instagram_media';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InstagramAccount::class, 'account_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function galleryItems(): HasMany
    {
        return $this->hasMany(InstagramGalleryItem::class, 'media_id');
    }

    public function isVideo(): bool
    {
        return $this->media_type === 'VIDEO';
    }

    /** 只有转存就绪的媒体才能发布到前台，未就绪的还挂在会过期的 IG CDN 上。 */
    public function isMirrorReady(): bool
    {
        return $this->mirror_status === 'ready';
    }

    /** 列表用的封面：优先转存后的封面，回退到 IG 缩略图或原图。 */
    public function previewUrl(): ?string
    {
        return $this->poster_url ?: ($this->ig_thumbnail_url ?: $this->ig_media_url);
    }

    /**
     * Instagram 官方 embed 地址，点击封面后在弹窗里嵌它。
     *
     * 视频文件不再转存（Instagram 对部分 Reels 不给 media_url），播放交给 Instagram 自己；
     * `captioned` 变体会连带文案渲染。permalink 结尾斜杠不一定有，统一补齐再拼。
     * 只认 instagram.com 的地址，避免把库里的脏数据直接塞进 iframe。
     */
    public function embedUrl(): ?string
    {
        $url = trim((string) $this->permalink);
        if ($url === '' || ! str_starts_with($url, 'https://www.instagram.com/')) {
            return null;
        }

        return rtrim($url, '/').'/embed/captioned';
    }

    /** @return list<string> */
    public function productGids(): array
    {
        return array_values(array_filter(
            is_array($this->product_ids) ? $this->product_ids : [],
            fn (mixed $id): bool => is_string($id) && $id !== '',
        ));
    }

    protected static function booted(): void
    {
        static::creating(function (InstagramMedia $media): void {
            $media->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'product_ids' => 'array',
            'posted_at' => 'datetime',
            'mirrored_at' => 'datetime',
            'like_count' => 'integer',
            'comments_count' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
        ];
    }
}
