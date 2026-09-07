#!/usr/bin/env bash
set -uo pipefail

cat > /tmp/kiro_probe.php <<'PHP'
<?php
require '/var/www/html/vendor/autoload.php';
$app = require_once '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$version = (string) config('shopify.api_version');
echo 'api_version='.$version.PHP_EOL;
echo 'instagram_env='.(string) config('instagram_feed.environment').PHP_EOL;
echo 'instagram_client_id='.(string) config('instagram_feed.active.client_id').PHP_EOL;
echo 'instagram_secret_len='.strlen((string) config('instagram_feed.active.client_secret')).PHP_EOL;

$store = App\Models\Store::query()->where('shopify_domain', 'macfoxebike.myshopify.com')->first();
echo 'store_id='.($store?->id ?? 'null').PHP_EOL;
$connection = $store?->shopifyConnection;
$token = (string) ($connection?->access_token_encrypted ?? '');
echo 'main_token_present='.($token !== '' ? 'yes' : 'no').PHP_EOL;
if ($token === '') {
    exit(0);
}

$endpoint = 'https://macfoxebike.myshopify.com/admin/api/'.$version.'/graphql.json';
foreach ([
    'shop' => '{ shop { name myshopifyDomain } }',
    'installation' => 'query { currentAppInstallation { id accessScopes { handle } } }',
] as $label => $query) {
    $response = Illuminate\Support\Facades\Http::acceptJson()->asJson()
        ->withHeaders(['X-Shopify-Access-Token' => $token])
        ->connectTimeout(5)->timeout(30)
        ->post($endpoint, ['query' => $query]);
    echo $label.'_status='.$response->status().PHP_EOL;
    echo $label.'_body='.mb_substr((string) $response->body(), 0, 500).PHP_EOL;
}
PHP

docker exec -i decoadmin-staging-app-1 sh -c 'cat > /tmp/kiro_probe.php' < /tmp/kiro_probe.php
echo "=== Shopify Admin API 只读探测（DecoAdmin 主 App token） ==="
docker exec -i decoadmin-staging-app-1 php /tmp/kiro_probe.php 2>&1 | tail -20
docker exec -i decoadmin-staging-app-1 rm -f /tmp/kiro_probe.php
rm -f /tmp/kiro_probe.php
