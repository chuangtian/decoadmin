<?php

namespace DecoReviews\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use DecoReviews\Models\ImportBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImportService
{
    public const PROVIDERS = ['custom', 'auto', 'loox', 'judge_me', 'yotpo', 'okendo', 'shopify_product_reviews'];

    private const FIELDS = [
        'custom' => ['product_handle' => ['product_handle'], 'rating' => ['rating'], 'author_name' => ['author_name'], 'body' => ['body'], 'reviewed_at' => ['reviewed_at'], 'author_email' => ['author_email'], 'title' => ['title']],
        'loox' => ['product_handle' => ['product_handle', 'product'], 'rating' => ['rating'], 'author_name' => ['author', 'author_name'], 'body' => ['body', 'review'], 'reviewed_at' => ['created_at', 'date'], 'author_email' => ['email'], 'title' => ['title']],
        'judge_me' => ['product_handle' => ['product_handle', 'product_id'], 'rating' => ['rating'], 'author_name' => ['reviewer_name'], 'body' => ['body'], 'reviewed_at' => ['review_date'], 'author_email' => ['reviewer_email'], 'title' => ['title']],
        'yotpo' => ['product_handle' => ['product_id', 'product_handle'], 'rating' => ['review_score', 'rating'], 'author_name' => ['reviewer_display_name', 'reviewer_name'], 'body' => ['review_content', 'body'], 'reviewed_at' => ['review_creation_date', 'review_date', 'created_at'], 'author_email' => ['reviewer_email'], 'title' => ['review_title', 'title']],
        'okendo' => ['product_handle' => ['product_id', 'handle', 'product_handle'], 'rating' => ['rating'], 'author_name' => ['reviewer_name', 'name'], 'body' => ['review_body', 'body'], 'reviewed_at' => ['date_created'], 'author_email' => ['reviewer_email', 'email'], 'title' => ['review_title', 'title']],
        'shopify_product_reviews' => ['product_handle' => ['product_handle'], 'rating' => ['rating'], 'author_name' => ['author'], 'body' => ['body'], 'reviewed_at' => ['created_at'], 'author_email' => ['email'], 'title' => ['title']],
    ];

    private const SIGNATURES = [
        'loox' => ['photo_url', 'video_url', 'verified_purchase', 'loox_review_id'],
        'judge_me' => ['reviewer_name', 'review_date'],
        'yotpo' => ['review_score', 'review_content', 'reviewer_display_name'],
        'okendo' => ['date_created'],
        'shopify_product_reviews' => ['state', 'location'],
    ];

    public function __construct(private ReviewService $reviews) {}

    public function import(Store $store, User $user, UploadedFile $file, string $provider = 'custom'): ImportBatch
    {
        $this->reviews->authorize($user, $store, true);
        Validator::make(['file' => $file, 'provider' => $provider], [
            'file' => 'required|file|max:15360|extensions:csv', 'provider' => 'required|in:'.implode(',', self::PROVIDERS),
        ])->validate();
        $handle = fopen($file->getRealPath(), 'r');
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        if (! is_array($header)) {
            throw ValidationException::withMessages(['file' => 'CSV 文件为空。']);
        }
        $header = array_map(fn ($key) => $this->normalizeHeader((string) $key), $header);
        if (count($header) !== count(array_unique($header))) {
            throw ValidationException::withMessages(['file' => 'CSV 表头包含重复字段。']);
        }
        $provider = $this->resolveProvider($provider, $header);
        $columns = $this->columns($provider, $header);
        $digest = hash('sha256', $provider.':'.hash_file('sha256', $file->getRealPath()));

        return DB::transaction(function () use ($store, $user, $file, $digest, $provider, $header, $columns) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $existing = ImportBatch::where('store_id', $store->id)->where('organization_id', $store->organization_id)->where('digest', $digest)->first();
            if ($existing) {
                return $existing;
            }
            $handle = fopen($file->getRealPath(), 'r');
            try {
                fgetcsv($handle, 0, ',', '"', '');
                $batch = ImportBatch::create(['uuid' => (string) Str::uuid(), 'organization_id' => $store->organization_id,
                    'store_id' => $store->id, 'user_id' => $user->id, 'digest' => $digest, 'provider' => $provider]);
                $errors = [];
                $imported = 0;
                $skipped = 0;
                $line = 1;
                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    $line++;
                    if ($row === [null]) {
                        continue;
                    }
                    if ($line > 1001) {
                        throw ValidationException::withMessages(['file' => '每批最多 1,000 条，请拆分文件导入。']);
                    }
                    if (count($row) !== count($header)) {
                        $errors[] = ['line' => $line, 'code' => 'COLUMN_COUNT'];

                        continue;
                    }
                    $source = array_combine($header, $row);
                    $input = collect($columns)->mapWithKeys(fn ($column, $field) => [$field => $column === null ? null : $source[$column]])->all();
                    $reference = trim((string) $input['product_handle']);
                    $product = Product::where('organization_id', $store->organization_id)->where('store_id', $store->id)
                        ->where($columns['product_handle'] === 'product_id' ? 'shopify_product_id' : 'handle', $reference)->first();
                    if (! $product) {
                        $errors[] = ['line' => $line, 'code' => 'PRODUCT_NOT_FOUND'];

                        continue;
                    }
                    try {
                        Validator::make($input, ['reviewed_at' => 'required|date|before_or_equal:now'])->validate();
                        $review = $this->reviews->create($store, array_merge($input, ['kind' => 'product', 'product_id' => $product->id]), [], $user,
                            ['source' => 'import', 'verified_source' => 'none', 'import_id' => $batch->id, 'reviewed_at' => CarbonImmutable::parse($input['reviewed_at'])]);
                        $review->wasRecentlyCreated ? $imported++ : $skipped++;
                    } catch (ValidationException) {
                        $errors[] = ['line' => $line, 'code' => 'INVALID_FIELDS'];
                    }
                }
                $batch->update(['imported' => $imported, 'skipped' => $skipped, 'errors' => array_slice($errors, 0, 100)]);
                $this->reviews->audit($store, $user, 'import.completed', null, ['batch' => $batch->uuid, 'provider' => $provider, 'imported' => $imported, 'skipped' => $skipped, 'error_count' => count($errors)]);

                return $batch;
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        });
    }

    private function normalizeHeader(string $header): string
    {
        $header = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', ltrim($header, "\xEF\xBB\xBF"));

        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($header)), '_');
    }

    private function resolveProvider(string $requested, array $header): string
    {
        if ($requested !== 'auto') {
            $this->columns($requested, $header);

            return $requested;
        }
        $matches = [];
        if (! array_diff(['product_handle', 'rating', 'author_name', 'body', 'reviewed_at'], $header)) {
            $matches[] = 'custom';
        }
        foreach (self::SIGNATURES as $provider => $signature) {
            $signatureMatches = $provider === 'loox' ? array_intersect($signature, $header) !== [] : ! array_diff($signature, $header);
            if ($signatureMatches) {
                try {
                    $this->columns($provider, $header);
                    $matches[] = $provider;
                } catch (ValidationException) {
                }
            }
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['provider' => $matches ? 'CSV 表头同时匹配多个来源，请明确选择 provider。' : '无法可靠识别 CSV 来源，请明确选择 provider。']);
        }

        return $matches[0];
    }

    private function columns(string $provider, array $header): array
    {
        $columns = [];
        foreach (self::FIELDS[$provider] as $field => $aliases) {
            $matches = array_values(array_intersect($aliases, $header));
            if (count($matches) > 1) {
                throw ValidationException::withMessages(['file' => "字段 {$field} 存在多个候选列，请只保留一个。"]);
            }
            $columns[$field] = $matches[0] ?? null;
        }
        $required = ['product_handle', 'rating', 'author_name', 'body', 'reviewed_at'];
        if (collect($required)->contains(fn ($field) => $columns[$field] === null)) {
            throw ValidationException::withMessages(['file' => '所选 provider 缺少必要字段：'.implode(', ', $required).'。']);
        }

        return $columns;
    }

    public function undo(Store $store, User $user, string $uuid): void
    {
        $this->reviews->authorize($user, $store, true);
        DB::transaction(function () use ($store, $user, $uuid) {
            $batch = ImportBatch::where('store_id', $store->id)->where('organization_id', $store->organization_id)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($batch->undone_at) {
                return;
            }
            if ($batch->created_at->lt(now()->subDays(7))) {
                throw ValidationException::withMessages(['import' => '只允许撤销最近 7 天的导入。']);
            }
            // Reversible withdrawal preserves the imported source and audit history.
            $this->reviews->scoped($store)->where('import_id', $batch->id)->update(['status' => 'unpublished', 'reason' => 'Import withdrawn', 'publish_at' => null, 'published_at' => null]);
            $batch->update(['status' => 'undone', 'undone_at' => now()]);
            $this->reviews->audit($store, $user, 'import.undone', null, ['batch' => $uuid]);
        });
    }
}
