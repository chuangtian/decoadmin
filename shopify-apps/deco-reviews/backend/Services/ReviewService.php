<?php

namespace DecoReviews\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use DecoReviews\Models\ImportBatch;
use DecoReviews\Models\Media;
use DecoReviews\Models\Review;
use DecoReviews\Models\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReviewService
{
    public function authorize(User $user, Store $store, bool $write = false): void
    {
        $this->active($store);
        abort_unless($user->canAccessStore($store)
            && $user->hasPermission('apps.view', $store->organization, $store)
            && $user->hasPermission('products.view', $store->organization, $store)
            && (! $write || $user->hasPermission('products.update', $store->organization, $store)), 403);
    }

    public function active(Store $store): void
    {
        abort_unless($store->status === 'active' && $store->organization?->status === 'active', 404);
    }

    public function scoped(Store $store): Builder
    {
        return Review::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id);
    }

    public function settings(Store $store): array
    {
        $values = Settings::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->value('values') ?? [];

        return array_replace(config('deco_reviews.defaults'), $values);
    }

    public function saveSettings(Store $store, User $user, array $input): void
    {
        $this->authorize($user, $store, true);
        $values = Validator::make($input, [
            'enabled' => 'required|boolean', 'auto_publish_days' => 'nullable|integer|min:0|max:90',
            'invites_enabled' => 'required|boolean', 'domestic_delay_days' => 'required|integer|min:0|max:180',
            'international_delay_days' => 'required|integer|min:0|max:180', 'reminder_days' => 'required|integer|min:1|max:90',
            'star_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'corner_style' => ['required', Rule::in(['rounded', 'square'])],
            'display_name' => ['required', Rule::in(['full', 'initials'])], 'show_verified' => 'required|boolean',
            'show_incentive' => 'required|boolean', 'layout' => ['required', Rule::in(['grid', 'list', 'mosaic'])],
            'page_size' => ['required', Rule::in([6, 12, 24])], 'heading' => 'required|string|max:120',
            'reply_to' => 'nullable|email:rfc|max:254', 'subject' => 'required|string|max:160',
            'email_body' => 'required|string|max:5000', 'marketing_only' => 'required|boolean',
        ])->validate();
        DB::transaction(function () use ($store, $user, $values) {
            Settings::query()->updateOrCreate(['store_id' => $store->id, 'organization_id' => $store->organization_id], ['values' => $values]);
            $this->audit($store, $user, 'settings.updated');
        });
    }

    public function filtered(Store $store, array $filters): Builder
    {
        $query = $this->scoped($store);
        foreach (['kind', 'status', 'rating', 'product_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query->where($key, $filters[$key]);
            }
        }
        if ($term = trim($filters['q'] ?? '')) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('author_name', 'like', $like)->orWhere('title', 'like', $like)->orWhere('body', 'like', $like)
                ->orWhere('email_hash', $this->emailHash($store, $term)));
        }
        if (in_array($filters['sort'] ?? '', ['rating_desc', 'rating_asc'])) {
            $query->orderBy('rating', $filters['sort'] === 'rating_desc' ? 'desc' : 'asc');
        }

        return $query->orderBy('reviewed_at', ($filters['sort'] ?? '') === 'oldest' ? 'asc' : 'desc')->orderBy('id', 'desc');
    }

    public function emailHash(Store $store, string $email): string
    {
        return hash_hmac('sha256', $store->organization_id.':'.$store->id.':'.Str::lower(trim($email)), config('app.key'));
    }

    public function create(Store $store, array $input, array $files = [], ?User $user = null, array $trusted = []): Review
    {
        $this->active($store);
        if ($user) {
            $this->authorize($user, $store, true);
        }
        $data = Validator::make($input, [
            'kind' => ['required', Rule::in(['product', 'store'])], 'product_id' => 'nullable|integer',
            'author_name' => 'required|string|max:120', 'author_email' => 'nullable|email:rfc|max:254',
            'rating' => 'required|integer|between:1,5', 'title' => 'nullable|string|max:200', 'body' => 'required|string|max:10000',
        ])->validate();
        if ($data['kind'] === 'product') {
            if (! Product::query()->where('organization_id', $store->organization_id)->where('store_id', $store->id)->whereKey($data['product_id'] ?? 0)->exists()) {
                throw ValidationException::withMessages(['product_id' => '请选择当前店铺的商品。']);
            }
        } else {
            $data['product_id'] = null;
        }
        if (isset($trusted['order_id'])) {
            $order = Order::where('organization_id', $store->organization_id)->where('store_id', $store->id)->whereKey($trusted['order_id'])->first();
            abort_unless($order && $order->items()->where('product_id', $data['product_id'])->exists(), 404);
        }
        if (isset($trusted['import_id'])) {
            abort_unless(ImportBatch::where('organization_id', $store->organization_id)->where('store_id', $store->id)->whereKey($trusted['import_id'])->exists(), 404);
        }
        abort_unless(in_array($trusted['source'] ?? 'merchant', ['merchant', 'email', 'import'])
            && in_array($trusted['verified_source'] ?? 'none', ['none', 'order'])
            && (($trusted['verified_source'] ?? 'none') !== 'order' || (isset($trusted['order_id']) && ($trusted['source'] ?? '') === 'email')), 422);
        Validator::make(['media' => $files], ['media' => 'array|max:5', 'media.*' => 'file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm|max:25600'])->validate();
        $videos = collect($files)->filter(fn ($file) => str_starts_with($file->getMimeType(), 'video/'))->count();
        if ($videos && count($files) !== 1) {
            throw ValidationException::withMessages(['media' => '最多 5 张图片，或单独 1 个视频。']);
        }
        $fingerprint = hash('sha256', json_encode([$data['kind'], $data['product_id'], Str::lower(trim($data['author_email'] ?? $data['author_name'])), $data['rating'], trim($data['body'])]));
        $paths = [];
        try {
            return DB::transaction(function () use ($store, $data, $trusted, $user, $files, $fingerprint, &$paths) {
                // Serialize inserts and import deduplication for this store.
                Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
                if ($existing = $this->scoped($store)->where('fingerprint', $fingerprint)->first()) {
                    return $existing;
                }
                $days = $this->settings($store)['auto_publish_days'];
                // Video originals require explicit moderation; they are never auto-published.
                if (collect($files)->contains(fn ($file) => str_starts_with($file->getMimeType(), 'video/'))) {
                    $days = null;
                }
                $review = Review::query()->create(array_merge($data, [
                    'uuid' => (string) Str::uuid(), 'organization_id' => $store->organization_id, 'store_id' => $store->id,
                    'email_hash' => isset($data['author_email']) ? $this->emailHash($store, $data['author_email']) : null,
                    'fingerprint' => $fingerprint, 'status' => $days === 0 ? 'published' : 'pending',
                    'publish_at' => $days === null ? null : now()->addDays($days), 'published_at' => $days === 0 ? now() : null,
                    'reviewed_at' => $trusted['reviewed_at'] ?? now(), 'source' => $trusted['source'] ?? 'merchant',
                    'verified_source' => $trusted['verified_source'] ?? 'none', 'order_id' => $trusted['order_id'] ?? null,
                    'import_id' => $trusted['import_id'] ?? null,
                ]));
                foreach ($files as $file) {
                    $uuid = (string) Str::uuid();
                    $mime = $file->getMimeType();
                    $path = 'deco-reviews/'.$store->organization_id.'/'.$store->id.'/'.$uuid;
                    if (str_starts_with($mime, 'image/')) {
                        $dimensions = @getimagesize($file->getRealPath());
                        if (! $dimensions || $dimensions[0] * $dimensions[1] > 12000000 || ! function_exists('imagecreatefromstring')) {
                            throw ValidationException::withMessages(['media' => '图片无法处理或超过 1,200 万像素限制。']);
                        }
                        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
                        if (! $image) {
                            throw ValidationException::withMessages(['media' => '图片内容无效。']);
                        }
                        ob_start();
                        try {
                            imagewebp($image, null, 85);
                            $bytes = ob_get_contents();
                        } finally {
                            ob_end_clean();
                            imagedestroy($image);
                        }
                        if (! Storage::disk('local')->put($path, $bytes)) {
                            throw new \RuntimeException('MEDIA_STORAGE_FAILED');
                        }
                        $mime = 'image/webp';
                        $size = strlen($bytes);
                    } else {
                        if (! $file->storeAs(dirname($path), $uuid, 'local')) {
                            throw new \RuntimeException('MEDIA_STORAGE_FAILED');
                        }
                        $size = $file->getSize();
                    }
                    $paths[] = $path;
                    Media::query()->create(['uuid' => $uuid, 'organization_id' => $store->organization_id, 'store_id' => $store->id,
                        'review_id' => $review->id, 'path' => $path, 'mime' => $mime, 'size' => $size,
                        'type' => str_starts_with($mime, 'video/') ? 'video' : 'image']);
                }
                $this->audit($store, $user, 'review.created', $review->id);

                return $review;
            });
        } catch (\Throwable $error) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
    }

    public function moderate(Store $store, User $user, array $ids, array $input): void
    {
        $this->authorize($user, $store, true);
        $data = Validator::make($input, ['status' => ['sometimes', Rule::in(['pending', 'published', 'unpublished'])],
            'reason' => 'required_if:status,unpublished|nullable|string|max:1000', 'featured' => 'sometimes|boolean', 'reply' => 'sometimes|nullable|string|max:5000'])->validate();
        Validator::make(['ids' => $ids], ['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|uuid|distinct'])->validate();
        DB::transaction(function () use ($store, $user, $ids, $data) {
            $reviews = $this->scoped($store)->whereIn('uuid', $ids)->lockForUpdate()->get();
            abort_unless($reviews->count() === count($ids), 404);
            foreach ($reviews as $review) {
                $values = $data;
                if (isset($data['status'])) {
                    $values['publish_at'] = null;
                    $values['published_at'] = $data['status'] === 'published' ? ($review->published_at ?? now()) : null;
                }
                $review->update($values);
                $this->audit($store, $user, 'review.moderated', $review->id, ['status' => $review->status, 'reply_changed' => array_key_exists('reply', $data)]);
            }
        });
    }

    public function serialize(Review $review, Store $store, bool $private = false, ?array $settings = null, bool $preview = false): array
    {
        $settings ??= $this->settings($store);
        $name = $review->author_name;
        if (! $private && $settings['display_name'] === 'initials') {
            $name = collect(preg_split('/\s+/u', trim($name)))->map(fn ($part) => mb_substr($part, 0, 1).'.')->join(' ');
        }
        $data = ['uuid' => $review->uuid, 'kind' => $review->kind, 'product_title' => $review->product?->title,
            'author_name' => $name, 'rating' => $review->rating, 'title' => $review->title, 'body' => $review->body,
            'reply' => $review->reply, 'source' => $review->source, 'verified_source' => $review->verified_source,
            'incentivized' => $review->incentivized, 'created_at' => $review->reviewed_at->toIso8601String(),
            'media' => $review->media->map(fn ($media) => ['uuid' => $media->uuid, 'type' => $media->type,
                'url' => ($private || $preview) ? route('deco-reviews.media', [$store->organization_id, $store->id, $media->uuid]) : route('deco-reviews.public-media', [$media->uuid])])->values()->all()];
        if ($private) {
            $data += ['author_email' => $review->author_email, 'product_id' => $review->product_id, 'status' => $review->status,
                'featured' => $review->featured, 'published_at' => $review->published_at?->toIso8601String(), 'reason' => $review->reason];
        }

        return $data;
    }

    public function audit(Store $store, ?User $user, string $action, ?int $id = null, array $metadata = []): void
    {
        AuditLog::query()->create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'user_id' => $user?->id,
            'action' => 'deco_reviews.'.$action, 'subject_type' => $id ? Review::class : Store::class, 'subject_id' => $id ?? $store->id, 'metadata' => $metadata]);
    }
}
