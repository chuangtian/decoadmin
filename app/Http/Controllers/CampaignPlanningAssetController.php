<?php

namespace App\Http\Controllers;

use App\Models\CampaignPlanningDocument;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignPlanningAssetController extends Controller
{
    public function __invoke(
        CampaignPlanningDocument $campaignPlanningDocument,
        string $assetHash,
        CurrentOrganization $currentOrganization,
        CurrentStore $currentStore,
    ): StreamedResponse {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $assetHash) === 1, 404);
        $organization = $currentOrganization->get();
        $store = $currentStore->get();
        abort_unless(
            $organization
            && $store
            && $campaignPlanningDocument->organization_id === $organization->id
            && $campaignPlanningDocument->store_id === $store->id,
            404,
        );

        $asset = $campaignPlanningDocument->assets()
            ->where('organization_id', $organization->id)
            ->where('store_id', $store->id)
            ->where('source_file_token_hash', $assetHash)
            ->firstOrFail();
        abort_unless(Storage::disk($asset->local_disk)->exists($asset->local_path), 404);

        return Storage::disk($asset->local_disk)->response(
            $asset->local_path,
            $asset->original_name,
            array_filter([
                'Content-Type' => $asset->mime_type,
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ]),
        );
    }
}
