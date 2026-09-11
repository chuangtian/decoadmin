<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AffiliateMaterialService
{
    public function listing(Organization $org, Store $store, User $actor): array
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.promoters.view');

        return DB::table('affiliate_assets')->where('organization_id', $org->id)->where('store_id', $store->id)->latest('id')
            ->limit(100)->get(['public_id', 'title', 'mime', 'created_at'])->toArray();
    }

    public function upload(Organization $org, Store $store, User $actor, UploadedFile $file, string $title): void
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.promoters.manage');
        $path = $file->store('affiliate-assets/'.$org->id.'/'.$store->id, 'local');
        try {
            DB::transaction(function () use ($org, $store, $actor, $file, $title, $path) {
                $id = DB::table('affiliate_assets')->insertGetId(['public_id' => (string) Str::ulid(), 'organization_id' => $org->id, 'store_id' => $store->id,
                    'title' => $title, 'path' => $path, 'mime' => $file->getMimeType(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                $this->audit($org, $store, $actor, $id, 'affiliate_asset_uploaded');
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function remove(Organization $org, Store $store, User $actor, string $publicId): void
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.promoters.manage');
        $path = DB::transaction(function () use ($org, $store, $actor, $publicId) {
            $row = DB::table('affiliate_assets')->where('organization_id', $org->id)->where('store_id', $store->id)->where('public_id', $publicId)->lockForUpdate()->first();
            abort_unless($row, 404);
            DB::table('affiliate_assets')->where('id', $row->id)->delete();
            $this->audit($org, $store, $actor, $row->id, 'affiliate_asset_deleted');

            return $row->path;
        });
        Storage::disk('local')->delete($path);
    }

    private function audit(Organization $org, Store $store, User $actor, int $id, string $action): void
    {
        AuditLog::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'user_id' => $actor->id, 'action' => $action, 'subject_type' => 'affiliate_asset', 'subject_id' => $id]);
    }
}
