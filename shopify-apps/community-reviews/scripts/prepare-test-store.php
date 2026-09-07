<?php

use App\Models\AuditLog;
use App\Models\ModelAssetFolder;
use App\Models\Product;
use App\Models\ReputationMention;
use App\Models\Store;
use App\Models\User;
use App\Services\ModelAssetLibraryService;
use CommunityReviews\Services\ReviewManager;
use CommunityReviews\Services\ShopifyClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('app.url') !== 'https://testadmin.decomkt.com' || config('community_reviews.environment') !== 'test') {
    throw new RuntimeException('This fixture is only for the DecoAdmin test environment.');
}
$store = Store::where('shopify_domain', 'macfox-test-app.myshopify.com')->firstOrFail();
$actor = User::findOrFail(1);
$manager = app(ReviewManager::class);
$manager->authorize($actor, $store, true);
$models = [
    '10264083857656' => ['X1S', 'CLASSIC MODELS', ['X1 S']],
    '10264083792120' => ['X2 Pro', 'UPGRADE MODELS', ['X2', 'X2Pro']],
    '10264086020344' => ['M16', 'CLASSIC MODELS', []],
    '10264086184184' => ['X7', 'UPGRADE MODELS', []],
];
$data = app(ShopifyClient::class)->graphql($store, <<<'GRAPHQL'
    query CommunityReviewTestMedia($ids: [ID!]!) {
      nodes(ids: $ids) {
        ... on Product { id title handle status publishedAt images(first: 12) { nodes { url width height } } }
      }
    }
    GRAPHQL, ['ids' => array_map(fn ($id) => 'gid://shopify/Product/'.$id, array_keys($models))]);
$library = app(ModelAssetLibraryService::class);
$links = [];
foreach ($data['nodes'] as $node) {
    if ($node['status'] !== 'ACTIVE' || ! $node['publishedAt']) throw new RuntimeException('Test product is not published.');
    $id = basename($node['id']);
    [$label, $series, $aliases] = $models[$id];
    $product = Product::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('shopify_product_id', $id)->firstOrFail();
    $folder = ModelAssetFolder::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('name', $label)->first();
    $folder ??= $library->createFolder($store, $actor, $label);
    foreach (array_slice($node['images']['nodes'], 0, 3) as $index => $image) {
        $name = 'shopify-test-'.$label.'-'.$index.'.webp';
        if ($folder->images()->where('original_name', $name)->exists()) continue;
        $url = $image['url'];
        if (parse_url($url, PHP_URL_HOST) !== 'cdn.shopify.com' || parse_url($url, PHP_URL_SCHEME) !== 'https') throw new RuntimeException('Unexpected asset source.');
        $response = Http::connectTimeout(10)->timeout(45)->get($url);
        if ($response->failed() || strlen($response->body()) > 20000000) throw new RuntimeException('Could not load product image.');
        $tmp = tempnam(sys_get_temp_dir(), 'review-asset-');
        try {
            file_put_contents($tmp, $response->body());
            $library->upload($store, $folder, $actor, new UploadedFile($tmp, $name, null, null, true));
        } finally {
            unlink($tmp);
        }
    }
    $links[] = ['folder_id' => $folder->uuid, 'product_id' => $product->id, 'label' => $label, 'series' => $series, 'aliases' => $aliases, 'enabled' => true];
    echo $label.': '.$folder->images()->count()." images ready\n";
}
$reviews = [
    ['X1S', 5, 'TEST REVIEW — This X1S card checks that the model mentioned in the comment matches the large photo, the product name and the product details link.'],
    ['X1S', 4, 'TEST REVIEW — A four-star X1S example for verifying the original rating and the shared Read more link.'],
    ['X2 Pro', 5, 'TEST REVIEW — The X2 Pro example checks matching a model name with a space, while keeping the corresponding product photo and link together.'],
    [null, 4, 'TEST REVIEW — This X2Pro example checks an alternative spelling and its four-star rating.'],
    ['M16', 5, 'TEST REVIEW — This M16 sample verifies the image folder and product link, with enough text to exercise the three-line comment preview.'],
    ['M16', 4, 'TEST REVIEW — A second M16 sample checks random selection within the same model folder.'],
    ['X7', 5, 'TEST REVIEW — An X7 comment checks that a model from the upgrade series keeps its own image and destination.'],
    ['X7', 4, 'TEST REVIEW — A four-star X7 sample checks the empty fifth star and consistent card height.'],
    [null, 5, 'TEST REVIEW — No model is mentioned here. The randomly selected photo decides which product name and details link appear underneath.'],
    [null, 4, 'TEST REVIEW — Another comment without a model, for checking that random images always stay paired with the correct product.'],
    [null, 5, 'TEST REVIEW — This card checks continuous dragging, with smooth movement from the last card back to the first.'],
    [null, 3, 'TEST REVIEW — This three-star sample must never appear in the storefront carousel.'],
    ['Retired Test Model', 5, 'TEST REVIEW — This unavailable model must never receive a random photo from another model.'],
];
foreach ($reviews as $index => [$model, $rating, $content]) {
    ReputationMention::updateOrCreate([
        'organization_id' => $store->organization_id, 'store_id' => $store->id,
        'source' => 'manual', 'canonical_key' => hash('sha256', 'community-reviews-test-'.$index),
    ], [
        'origin' => 'manual', 'reviewer_name' => 'TEST '.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
        'title' => 'Community Reviews test fixture', 'content' => $content, 'rating' => $rating,
        'model_name' => $model, 'is_active' => true, 'synced_at' => now(), 'published_at' => now(),
    ]);
}
$manager->save($store, $actor, ['enabled' => true, 'heading' => 'HEAR FROM THE MACFOX E-BIKE COMMUNITY',
    'read_more_url' => 'https://www.trustpilot.com/review/macfoxbike.com', 'card_count' => 12, 'models' => $links]);
AuditLog::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'user_id' => $actor->id,
    'action' => 'community_reviews_test_fixtures_prepared', 'subject_type' => Store::class, 'subject_id' => $store->id,
    'metadata' => ['test_only' => true, 'models' => count($links), 'comments' => count($reviews)]]);
echo "Prepared test-store-only models, images and clearly marked test reviews.\n";
