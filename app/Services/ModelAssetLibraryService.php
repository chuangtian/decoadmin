<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ModelAssetFolder;
use App\Models\ModelAssetImage;
use App\Models\Store;
use App\Models\User;
use App\Services\Media\ImageOptimizationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ModelAssetLibraryService
{
    private const DISK = 'local';

    public function __construct(private ImageOptimizationService $imageOptimizer) {}

    public function findFolder(Store $store, string $uuid): ModelAssetFolder
    {
        return ModelAssetFolder::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function findImage(Store $store, string $uuid): ModelAssetImage
    {
        return ModelAssetImage::query()
            ->where('organization_id', $store->organization_id)
            ->where('store_id', $store->getKey())
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    public function createFolder(Store $store, User $actor, string $name): ModelAssetFolder
    {
        $folder = ModelAssetFolder::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->getKey(),
            'created_by' => $actor->getKey(),
            'name' => trim($name),
        ]);

        $this->audit($store, $actor, 'model_asset_folder_created', $folder, [
            'folder_uuid' => $folder->uuid,
            'folder_name' => $folder->name,
        ]);

        return $folder;
    }

    public function upload(Store $store, ModelAssetFolder $folder, User $actor, UploadedFile $file): ModelAssetImage
    {
        $this->assertFolderScope($store, $folder);
        $contents = $file->getContent();
        $thumbnail = $this->imageOptimizer->toWebp($contents, '车型素材图片', 640);
        $imageInfo = getimagesizefromstring($contents);
        if (! is_array($imageInfo) || ! isset($imageInfo[0], $imageInfo[1], $imageInfo['mime'])) {
            throw new RuntimeException('车型素材图片不是有效图片。');
        }

        $mimeType = strtolower((string) $imageInfo['mime']);
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new RuntimeException('车型素材图片格式不受支持。'),
        };
        $uuid = (string) Str::uuid();
        $path = sprintf(
            'model-assets/%d/%d/%s/original/%s.%s',
            $store->organization_id,
            $store->getKey(),
            $folder->uuid,
            $uuid,
            $extension,
        );
        $thumbnailPath = sprintf(
            'model-assets/%d/%d/%s/thumbnails/%s.webp',
            $store->organization_id,
            $store->getKey(),
            $folder->uuid,
            $uuid,
        );

        if (! Storage::disk(self::DISK)->put($path, $contents)) {
            throw new RuntimeException('图片保存失败，请稍后重试。');
        }
        if (! Storage::disk(self::DISK)->put($thumbnailPath, $thumbnail['contents'])) {
            Storage::disk(self::DISK)->delete($path);

            throw new RuntimeException('图片缩略图保存失败，请稍后重试。');
        }

        try {
            return DB::transaction(function () use ($store, $folder, $actor, $file, $contents, $imageInfo, $mimeType, $uuid, $path, $thumbnailPath): ModelAssetImage {
                $lockedFolder = ModelAssetFolder::query()->lockForUpdate()->find($folder->getKey());
                if (! $lockedFolder) {
                    throw new RuntimeException('目标文件夹已被删除。');
                }
                $this->assertFolderScope($store, $lockedFolder);

                $image = ModelAssetImage::query()->create([
                    'uuid' => $uuid,
                    'organization_id' => $store->organization_id,
                    'store_id' => $store->getKey(),
                    'folder_id' => $lockedFolder->getKey(),
                    'uploaded_by' => $actor->getKey(),
                    'disk' => self::DISK,
                    'path' => $path,
                    'thumbnail_path' => $thumbnailPath,
                    'original_name' => Str::limit(basename((string) $file->getClientOriginalName()), 255, ''),
                    'mime_type' => $mimeType,
                    'byte_size' => strlen($contents),
                    'width' => (int) $imageInfo[0],
                    'height' => (int) $imageInfo[1],
                ]);

                $this->audit($store, $actor, 'model_asset_image_uploaded', $image, [
                    'folder_uuid' => $lockedFolder->uuid,
                    'image_uuid' => $image->uuid,
                    'width' => $image->width,
                    'height' => $image->height,
                    'byte_size' => $image->byte_size,
                ]);

                return $image;
            });
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);
            Storage::disk(self::DISK)->delete($thumbnailPath);

            throw $exception;
        }
    }

    public function deleteImage(Store $store, ModelAssetImage $image, User $actor): void
    {
        $this->assertImageScope($store, $image);

        DB::transaction(function () use ($store, $image, $actor): void {
            $locked = ModelAssetImage::query()->lockForUpdate()->findOrFail($image->getKey());
            $this->assertImageScope($store, $locked);
            $this->deletePhysicalFiles($this->imageFiles($locked));

            $snapshot = [
                'folder_id' => $locked->folder_id,
                'image_uuid' => $locked->uuid,
                'byte_size' => $locked->byte_size,
            ];
            $locked->delete();
            $this->audit($store, $actor, 'model_asset_image_deleted', $locked, $snapshot);
        });
    }

    public function clearFolder(Store $store, ModelAssetFolder $folder, User $actor): int
    {
        $this->assertFolderScope($store, $folder);

        return DB::transaction(function () use ($store, $folder, $actor): int {
            $locked = ModelAssetFolder::query()->lockForUpdate()->findOrFail($folder->getKey());
            $this->assertFolderScope($store, $locked);
            $images = ModelAssetImage::query()
                ->where('folder_id', $locked->getKey())
                ->lockForUpdate()
                ->get(['id', 'disk', 'path', 'thumbnail_path']);
            $this->deletePhysicalFiles($images->flatMap(fn (ModelAssetImage $image): array => $this->imageFiles($image))->all());
            $count = $images->count();
            ModelAssetImage::query()->whereIn('id', $images->modelKeys())->delete();

            $this->audit($store, $actor, 'model_asset_folder_cleared', $locked, [
                'folder_uuid' => $locked->uuid,
                'deleted_images' => $count,
            ]);

            return $count;
        });
    }

    public function deleteFolder(Store $store, ModelAssetFolder $folder, User $actor): int
    {
        $this->assertFolderScope($store, $folder);

        return DB::transaction(function () use ($store, $folder, $actor): int {
            $locked = ModelAssetFolder::query()->lockForUpdate()->findOrFail($folder->getKey());
            $this->assertFolderScope($store, $locked);
            $images = ModelAssetImage::query()
                ->where('folder_id', $locked->getKey())
                ->lockForUpdate()
                ->get(['id', 'disk', 'path', 'thumbnail_path']);
            $this->deletePhysicalFiles($images->flatMap(fn (ModelAssetImage $image): array => $this->imageFiles($image))->all());
            $count = $images->count();
            $snapshot = [
                'folder_uuid' => $locked->uuid,
                'folder_name' => $locked->name,
                'deleted_images' => $count,
            ];
            $locked->delete();
            $this->audit($store, $actor, 'model_asset_folder_deleted', $locked, $snapshot);

            return $count;
        });
    }

    /** @param list<array{disk: string, path: string}> $files */
    private function deletePhysicalFiles(array $files): void
    {
        foreach (collect($files)->groupBy('disk') as $disk => $group) {
            foreach ($group->pluck('path')->filter()->values()->chunk(100) as $paths) {
                Storage::disk((string) $disk)->delete($paths->all());
                foreach ($paths as $path) {
                    if (Storage::disk((string) $disk)->exists((string) $path)) {
                        throw new RuntimeException('图片文件删除失败，未修改数据库记录。');
                    }
                }
            }
        }
    }

    /** @return list<array{disk: string, path: string}> */
    private function imageFiles(ModelAssetImage $image): array
    {
        $paths = array_values(array_unique(array_filter([
            $image->path,
            $image->thumbnail_path,
        ])));

        return array_map(fn (string $path): array => [
            'disk' => $image->disk,
            'path' => $path,
        ], $paths);
    }

    private function assertFolderScope(Store $store, ModelAssetFolder $folder): void
    {
        abort_unless(
            (int) $folder->organization_id === (int) $store->organization_id
            && (int) $folder->store_id === (int) $store->getKey(),
            404,
        );
    }

    private function assertImageScope(Store $store, ModelAssetImage $image): void
    {
        abort_unless(
            (int) $image->organization_id === (int) $store->organization_id
            && (int) $image->store_id === (int) $store->getKey(),
            404,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function audit(Store $store, User $actor, string $action, object $subject, array $metadata): void
    {
        AuditLog::query()->create([
            'organization_id' => $store->organization_id,
            'store_id' => $store->getKey(),
            'user_id' => $actor->getKey(),
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->id,
            'metadata' => ['scope' => 'store', ...$metadata],
        ]);
    }
}
