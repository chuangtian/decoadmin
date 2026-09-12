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
    public function __construct(private ReviewService $reviews) {}

    public function import(Store $store, User $user, UploadedFile $file): ImportBatch
    {
        $this->reviews->authorize($user, $store, true);
        Validator::make(['file' => $file], ['file' => 'required|file|max:15360|extensions:csv'])->validate();
        $digest = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($store, $user, $file, $digest) {
            Store::whereKey($store->id)->lockForUpdate()->firstOrFail();
            $existing = ImportBatch::where('store_id', $store->id)->where('organization_id', $store->organization_id)->where('digest', $digest)->first();
            if ($existing) {
                return $existing;
            }
            $handle = fopen($file->getRealPath(), 'r');
            try {
                $header = fgetcsv($handle, 0, ',', '"', '');
                if (! is_array($header)) {
                    throw ValidationException::withMessages(['file' => 'CSV 文件为空。']);
                }
                $header = array_map(fn ($key) => trim(ltrim((string) $key, "\xEF\xBB\xBF")), $header);
                $required = ['product_handle', 'rating', 'author_name', 'body', 'reviewed_at'];
                if (array_diff($required, $header) || count($header) !== count(array_unique($header))) {
                    throw ValidationException::withMessages(['file' => '需要不重复的字段：'.implode(', ', $required).'。可选：author_email、title。']);
                }
                $batch = ImportBatch::create(['uuid' => (string) Str::uuid(), 'organization_id' => $store->organization_id,
                    'store_id' => $store->id, 'user_id' => $user->id, 'digest' => $digest]);
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
                    $input = array_combine($header, $row);
                    $product = Product::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('handle', $input['product_handle'])->first();
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
                $this->reviews->audit($store, $user, 'import.completed', null, ['batch' => $batch->uuid, 'imported' => $imported, 'skipped' => $skipped, 'error_count' => count($errors)]);

                return $batch;
            } finally {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        });
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
