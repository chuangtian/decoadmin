<?php

namespace App\Http\Controllers;

use App\Models\ModelAssetFolder;
use App\Models\ModelAssetImage;
use App\Models\Organization;
use App\Models\Store;
use App\Services\ModelAssetLibraryService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ModelAssetController extends Controller
{
    public function __construct(private ModelAssetLibraryService $assets) {}

    public function index(Request $request, CurrentOrganization $currentOrganization, CurrentStore $currentStore): Response
    {
        [$organization, $store] = $this->scope($request, $currentOrganization, $currentStore, 'products.view');
        $folders = ModelAssetFolder::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->withCount('images')
            ->orderBy('name')
            ->paginate(60)
            ->through(fn (ModelAssetFolder $folder): array => [
                'id' => $folder->uuid,
                'name' => $folder->name,
                'image_count' => (int) $folder->images_count,
                'created_at' => $folder->created_at?->toIso8601String(),
            ]);

        return Inertia::render('ModelAssets/Index', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'folders' => $folders,
            'permissions' => [
                'manage' => $request->user()->hasPermission('products.update', $organization, $store),
            ],
        ]);
    }

    public function storeFolder(Request $request, CurrentOrganization $currentOrganization, CurrentStore $currentStore): RedirectResponse
    {
        [$organization, $store] = $this->scope($request, $currentOrganization, $currentStore, 'products.update');
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('model_asset_folders', 'name')->where('store_id', $store->getKey()),
            ],
        ], [
            'name.required' => '请输入文件夹名称。',
            'name.unique' => '当前店铺已经有同名文件夹。',
            'name.max' => '文件夹名称不能超过 80 个字符。',
        ]);

        $this->assets->createFolder($store, $request->user(), $validated['name']);

        return back()->with('success', '文件夹已创建。');
    }

    public function showFolder(
        Request $request,
        string $folder,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): Response {
        [$organization, $store] = $this->scope($request, $currentOrganization, $currentStore, 'products.view');
        $assetFolder = $this->assets->findFolder($store, $folder);
        $images = ModelAssetImage::query()
            ->where('organization_id', $organization->getKey())
            ->where('store_id', $store->getKey())
            ->where('folder_id', $assetFolder->getKey())
            ->latest('created_at')
            ->paginate(80)
            ->through(fn (ModelAssetImage $image): array => $this->imagePayload($image));

        return Inertia::render('ModelAssets/Show', [
            'store' => ['id' => $store->id, 'name' => $store->name],
            'folder' => [
                'id' => $assetFolder->uuid,
                'name' => $assetFolder->name,
                'image_count' => $assetFolder->images()->count(),
            ],
            'images' => $images,
            'permissions' => [
                'manage' => $request->user()->hasPermission('products.update', $organization, $store),
            ],
        ]);
    }

    public function upload(
        Request $request,
        string $folder,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): JsonResponse {
        [, $store] = $this->scope($request, $currentOrganization, $currentStore, 'products.update');
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'],
        ], [
            'image.required' => '请选择要上传的图片。',
            'image.image' => '只能上传有效图片。',
            'image.mimes' => '仅支持 JPG、PNG 和 WebP 图片。',
            'image.max' => '单张图片不能超过 20MB。',
        ]);
        $assetFolder = $this->assets->findFolder($store, $folder);
        $image = $this->assets->upload($store, $assetFolder, $request->user(), $request->file('image'));

        return response()->json(['data' => $this->imagePayload($image)], 201);
    }

    public function image(
        Request $request,
        string $image,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): StreamedResponse {
        [, $store] = $this->scope($request, $currentOrganization, $currentStore, 'products.view');
        $assetImage = $this->assets->findImage($store, $image);
        abort_unless(Storage::disk($assetImage->disk)->exists($assetImage->path), 404);
        $extension = pathinfo($assetImage->path, PATHINFO_EXTENSION) ?: 'img';

        return Storage::disk($assetImage->disk)->response(
            $assetImage->path,
            $assetImage->uuid.'.'.$extension,
            [
                'Content-Type' => $assetImage->mime_type,
                'Content-Disposition' => 'inline; filename="'.$assetImage->uuid.'.'.$extension.'"',
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }

    public function thumbnail(
        Request $request,
        string $image,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): StreamedResponse {
        [, $store] = $this->scope($request, $currentOrganization, $currentStore, 'products.view');
        $assetImage = $this->assets->findImage($store, $image);
        $path = $assetImage->thumbnail_path ?: $assetImage->path;
        abort_unless(Storage::disk($assetImage->disk)->exists($path), 404);

        return Storage::disk($assetImage->disk)->response(
            $path,
            $assetImage->uuid.'-thumbnail.webp',
            [
                'Content-Type' => $assetImage->thumbnail_path ? 'image/webp' : $assetImage->mime_type,
                'Content-Disposition' => 'inline; filename="'.$assetImage->uuid.'-thumbnail.webp"',
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }

    public function destroyImage(
        Request $request,
        string $image,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): JsonResponse {
        [, $store] = $this->scope($request, $currentOrganization, $currentStore, 'products.update');
        $assetImage = $this->assets->findImage($store, $image);
        $this->assets->deleteImage($store, $assetImage, $request->user());

        return response()->json(['message' => '图片已永久删除。']);
    }

    public function clearFolder(
        Request $request,
        string $folder,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): JsonResponse {
        [, $store] = $this->scope($request, $currentOrganization, $currentStore, 'products.update');
        $assetFolder = $this->assets->findFolder($store, $folder);
        $deleted = $this->assets->clearFolder($store, $assetFolder, $request->user());

        return response()->json(['message' => '当前文件夹已清空。', 'deleted' => $deleted]);
    }

    public function destroyFolder(
        Request $request,
        string $folder,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): RedirectResponse {
        [, $store] = $this->scope($request, $currentOrganization, $currentStore, 'products.update');
        $assetFolder = $this->assets->findFolder($store, $folder);
        $deleted = $this->assets->deleteFolder($store, $assetFolder, $request->user());

        return to_route('model-assets.index')->with('success', "文件夹及其中 {$deleted} 张图片已永久删除。");
    }

    /** @return array{0: Organization, 1: Store} */
    private function scope(
        Request $request,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
        string $permission,
    ): array {
        $organization = $currentOrganization->require();
        $store = $currentStore->require();
        abort_unless((int) $store->organization_id === (int) $organization->getKey(), 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission($permission, $organization, $store), 403);

        return [$organization, $store];
    }

    /** @return array<string, int|string> */
    private function imagePayload(ModelAssetImage $image): array
    {
        return [
            'id' => $image->uuid,
            'url' => route('model-assets.images.content', ['image' => $image->uuid]),
            'thumbnail_url' => route('model-assets.images.thumbnail', ['image' => $image->uuid]),
            'width' => $image->width,
            'height' => $image->height,
            'byte_size' => $image->byte_size,
            'created_at' => $image->created_at?->toIso8601String() ?? '',
        ];
    }
}
