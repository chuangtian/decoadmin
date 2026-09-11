<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Store;
use App\Services\BrandProfileService;
use App\Support\CurrentStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BrandProfileController extends Controller
{
    public function __invoke(Request $request, CurrentStore $currentStore, BrandProfileService $profiles, string $section = 'overview'): Response
    {
        $store = $currentStore->get();

        if ($store) {
            $this->authorize('view', $store);
        }

        return Inertia::render('BrandProfile/Index', [
            'section' => $section,
            'store' => $store?->only(['id', 'name', 'shopify_domain', 'currency', 'timezone']),
            'profile' => $store ? $profiles->page($store, $section) : null,
            'canReveal' => $store && $request->user()->hasPermission('store.update', $store->organization, $store),
        ]);
    }

    public function refresh(int $storeId, CurrentStore $currentStore, BrandProfileService $profiles): RedirectResponse
    {
        $store = $currentStore->require();
        abort_unless($store->id === $storeId, 404);
        $this->authorize('update', $store);
        try {
            $profiles->sync($store);
        } catch (Throwable $exception) {
            Log::warning('Brand profile sync failed.', ['store_id' => $storeId, 'exception' => $exception::class]);

            return back()->withErrors(['brand_profile' => '品牌资料同步失败，已保留原有内容。请检查飞书原表配置与读取权限。']);
        }

        return back()->with('success', '品牌资料已从飞书更新。');
    }

    public function password(Request $request, int $storeId, string $section, string $rowId, CurrentStore $currentStore, BrandProfileService $profiles): JsonResponse
    {
        $store = $currentStore->require();
        abort_unless($store->id === $storeId, 404);
        $this->authorize('update', $store);
        $value = $profiles->password($store, $section, $rowId);
        AuditLog::query()->create([
            'organization_id' => $store->organization_id, 'store_id' => $store->id, 'user_id' => $request->user()->id,
            'action' => 'brand_profile_password_viewed', 'subject_type' => Store::class, 'subject_id' => $store->id,
            'metadata' => ['section' => $section, 'row_id' => $rowId],
        ]);

        return response()->json(['data' => ['value' => $value]])->withHeaders(['Cache-Control' => 'no-store, private', 'Pragma' => 'no-cache']);
    }

    public function file(int $storeId, string $assetId, CurrentStore $currentStore, BrandProfileService $profiles): \Symfony\Component\HttpFoundation\Response
    {
        $store = $currentStore->require();
        abort_unless($store->id === $storeId, 404);
        $this->authorize('view', $store);
        $file = $profiles->file($store, $assetId);

        return response($file['contents'])->withHeaders([
            'Content-Type' => $file['mime'], 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => "inline; filename*=UTF-8''".rawurlencode($file['name']),
            'Content-Security-Policy' => "frame-ancestors 'self'",
        ]);
    }
}
