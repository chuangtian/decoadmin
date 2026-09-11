<?php

use App\Models\ReputationMention;
use App\Models\Store;
use App\Models\User;
use App\Services\Reputation\ReputationWorkflowService;
use CommunityReviews\Services\ReviewManager;
use Illuminate\Support\Facades\DB;

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (config('app.url') !== 'https://testadmin.decomkt.com' || config('community_reviews.environment') !== 'test') {
    throw new RuntimeException('Demo reviews are restricted to the test environment.');
}
$store = Store::where('shopify_domain', 'macfox-test-app.myshopify.com')->firstOrFail();
$actor = User::findOrFail(1);
app(ReviewManager::class)->authorize($actor, $store, true);
$workflow = app(ReputationWorkflowService::class);
$reviews = [
    ['Alex', 5, 'X1S', 'The X1S has made my everyday rides much more enjoyable. I love the relaxed riding position and the clean design. It has become my favorite way to get around the neighborhood.'],
    ['Jamie', 4, 'X1S', 'Really enjoying my X1S so far. It feels comfortable on my usual route, and the styling looks even better in person. I would have liked a little more detail in the setup instructions.'],
    ['Taylor', 5, 'X2 Pro', 'Love the look and feel of the X2 Pro. It is the bike I keep reaching for when I want to get outside and explore. The riding position feels natural and comfortable to me.'],
    ['Morgan', 4, null, 'The X2Pro has been a fun addition to my weekends. I like the design and the way it feels on familiar routes. It took a couple of rides to get everything adjusted just how I wanted.'],
    ['Jordan', 5, 'M16', 'The M16 is a great fit for my everyday rides. It feels easy to get comfortable with, and I really like its simple, compact look. I am finding more reasons to leave the car at home.'],
    ['Riley', 5, 'X7', 'The X7 looks fantastic in person. I enjoy taking it out for an evening ride, and the comfortable seat makes those little trips something I look forward to. Very happy with the overall feel.'],
    ['Casey', 4, null, 'A fun bike for casual rides and quick errands. I am still getting used to the controls, but the first few rides have been enjoyable. The design was what caught my attention in the first place.'],
    ['Sam', 5, null, 'This has quickly become my favorite way to spend an hour outdoors. The bike looks great, feels comfortable to ride, and makes even a familiar route feel like a small adventure.'],
];

$result = DB::transaction(function () use ($store, $actor, $workflow, $reviews): array {
    $scope = ['organization_id' => $store->organization_id, 'store_id' => $store->id];
    $created = 0;
    $updated = 0;
    foreach ($reviews as $index => [$name, $rating, $model, $content]) {
        $key = hash('sha256', 'community-reviews-demo-20260905-'.$index);
        $mention = ReputationMention::where($scope)->where('source', 'manual')->where('canonical_key', $key)->first();
        if (! $mention) {
            $mention = $workflow->createMention($store, $actor, [
                'source' => 'manual', 'title' => 'Community Reviews demo',
                'content' => $content, 'rating' => $rating, 'model_name' => $model,
                'published_at' => now()->subDays($index + 1)->toIso8601String(),
            ]);
            $created++;
        } else {
            $updated++;
        }
        $workflow->updateMention($store, $actor, $mention, [
            'canonical_key' => $key, 'reviewer_name' => 'Demo · '.$name,
            'title' => 'Community Reviews demo', 'content' => $content,
            'rating' => $rating, 'model_name' => $model, 'is_active' => true,
            'metrics' => ['demo' => true, 'fixture' => 'community-reviews-demo-20260905'],
        ]);
    }
    $oldKeys = array_map(fn ($index) => hash('sha256', 'community-reviews-test-'.$index), range(0, 12));
    $old = ReputationMention::where($scope)->where('source', 'manual')->whereIn('canonical_key', $oldKeys)
        ->where('content', 'like', 'TEST REVIEW%')->where('is_active', true)->get();
    foreach ($old as $mention) {
        $workflow->updateMention($store, $actor, $mention, ['is_active' => false]);
    }
    return ['created' => $created, 'updated' => $updated, 'retired_technical_fixtures' => $old->count()];
});

echo json_encode(['store' => $store->name, ...$result], JSON_UNESCAPED_UNICODE).PHP_EOL;
