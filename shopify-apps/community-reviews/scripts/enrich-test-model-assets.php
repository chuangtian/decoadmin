<?php

use App\Models\ModelAssetFolder;
use App\Models\ModelAssetImage;
use App\Models\Store;
use App\Models\User;
use App\Services\ModelAssetLibraryService;
use CommunityReviews\Services\ReviewManager;
use CommunityReviews\Services\ShopifyClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('app.url') !== 'https://testadmin.decomkt.com' || config('community_reviews.environment') !== 'test') {
    throw new RuntimeException('These assets are restricted to the test environment.');
}
$store = Store::where('shopify_domain', 'macfox-test-app.myshopify.com')->firstOrFail();
$actor = User::findOrFail(1);
app(ReviewManager::class)->authorize($actor, $store, true);
$shopify = app(ShopifyClient::class);
$library = app(ModelAssetLibraryService::class);
$scope = ['organization_id' => $store->organization_id, 'store_id' => $store->id];
$target = 15;

// MediaImage IDs inspected in this test store's Shopify Files, grouped by model.
$models = [
    'X1S' => ['product' => '10264083857656', 'media' => [
        '41918245503224', '41918245470456', '41918245437688', '41918245404920',
        '41918245372152', '41918245339384', '41918245306616', '41918245273848',
        '41918245241080', '41918245208312', '41918245175544', '41918245110008',
        '41918245077240', '41918245044472', '41918246617336',
    ]],
    'X2 Pro' => ['product' => '10264083792120', 'media' => [
        '41918247862520', '41918247567608', '41918247600376', '41918247960824',
        '41918512005368', '41918511841528', '41918511808760', '41918511775992',
        '41918511743224', '41918511710456', '41918511677688', '41918511644920',
        '41918511612152', '41918511579384', '41918511513848', '41918511481080',
        '41918511448312', '41918511382776', '41918511350008', '41918511284472',
    ]],
    'M16' => ['product' => '10264086020344', 'media' => [
        '41918239703288', '41918239670520', '41918239637752', '41918239604984',
        '41918239572216', '41918239539448', '41918239441144', '41918239408376',
        '41918239375608', '41918239342840', '41918239310072', '41918239277304',
        '41918239244536', '41918239211768', '41918239179000', '41918239113464',
        '41918239047928', '41918239015160', '41918238982392',
    ]],
    'X7' => ['product' => '10264086184184', 'media' => [
        '41918257070328', '41918257037560', '41918257004792', '41918256939256',
        '41918256906488', '41918256873720', '41918256840952', '41918256808184',
        '41918256775416', '41918256742648', '41918256709880', '41918256677112',
        '41918256644344', '41918256611576', '41918256578808', '41918256513272',
    ]],
];

$products = $shopify->graphql($store, <<<'GRAPHQL'
query AssetInventory($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on Product { id status publishedAt images(first: 100) { nodes { url width height } } }
  }
}
GRAPHQL, ['ids' => array_map(fn ($model) => 'gid://shopify/Product/'.$model['product'], array_values($models))]);
$products = collect($products['nodes'])->keyBy('id');
$mediaIds = array_unique(array_merge(...array_column($models, 'media')));
$media = [];
foreach (array_chunk($mediaIds, 100) as $ids) {
    $data = $shopify->graphql($store, <<<'GRAPHQL'
query AssetById($ids: [ID!]!) {
  nodes(ids: $ids) { ... on MediaImage { id image { url width height } } }
}
GRAPHQL, ['ids' => array_map(fn ($id) => 'gid://shopify/MediaImage/'.$id, $ids)]);
    foreach ($data['nodes'] as $node) {
        if (! empty($node['image'])) $media[basename($node['id'])] = $node['image'];
    }
}

$hashes = [];
foreach (ModelAssetImage::where($scope)->get() as $image) {
    $hashes[hash('sha256', Storage::disk($image->disk)->get($image->path))] = true;
}
$summary = [];
foreach ($models as $label => $model) {
    $product = $products->get('gid://shopify/Product/'.$model['product']);
    if (($product['status'] ?? '') !== 'ACTIVE' || empty($product['publishedAt']) || strtotime($product['publishedAt']) > time()) {
        throw new RuntimeException($label.' is not currently published.');
    }
    $folder = ModelAssetFolder::where($scope)->where('name', $label)->firstOrFail();
    $candidates = [];
    foreach ($model['media'] as $id) {
        if (isset($media[$id])) $candidates[] = $media[$id];
    }
    $candidates = [...$candidates, ...$product['images']['nodes']];
    $added = 0;
    $duplicates = 0;
    foreach ($candidates as $candidate) {
        if ($folder->images()->count() >= $target) break;
        if (min($candidate['width'], $candidate['height']) < 400) continue;
        $url = $candidate['url'];
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'cdn.shopify.com') {
            throw new RuntimeException('Unexpected image source.');
        }
        $response = Http::connectTimeout(10)->timeout(45)->withOptions(['allow_redirects' => false])->get($url);
        if (! $response->successful() || strlen($response->body()) > 20000000) {
            throw new RuntimeException('Could not load a Shopify model image.');
        }
        $body = $response->body();
        $hash = hash('sha256', $body);
        if (isset($hashes[$hash])) { $duplicates++; continue; }
        $info = @getimagesizefromstring($body);
        if (! $info || ! in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) continue;
        $tmp = tempnam(sys_get_temp_dir(), 'community-review-asset-');
        try {
            file_put_contents($tmp, $body);
            $name = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
            $library->upload($store, $folder, $actor, new UploadedFile($tmp, $name, null, null, true));
            $hashes[$hash] = true;
            $added++;
            echo $label.': added '.$added.PHP_EOL;
        } finally {
            unlink($tmp);
        }
    }
    $summary[$label] = ['added' => $added, 'duplicates_skipped' => $duplicates, 'total' => $folder->images()->count()];
}
echo json_encode(['store' => $store->shopify_domain, 'models' => $summary], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
if (array_sum(array_column($summary, 'total')) < 50) throw new RuntimeException('The minimum of 50 images was not reached.');
