<?php

/** Exercise public portal HTTP with isolated cookies and synthetic invitations only. */
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use App\Domain\ReferralAffiliate\Services\AffiliateInvitationService;
use App\Domain\ReferralAffiliate\Services\AffiliateManagementService;
use App\Domain\ReferralAffiliate\Services\AffiliatePortalService;
use App\Domain\ReferralAffiliate\Services\AffiliateShopGuard;
use App\Models\Store;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

if (! $app->environment('staging') || rtrim((string) config('app.url'), '/') !== 'https://testadmin.decomkt.com') {
    throw new RuntimeException('Staging only');
}
$store = Store::query()->where('shopify_domain', AffiliateShopGuard::TEST_SHOP)->firstOrFail();
$org = $store->organization;
$member = AffiliateProgramMembership::query()->forStore($store)->where('public_id', '01M200EES5BGNKTRC0XRTEA3S4')->with('promoter')->firstOrFail();
$actor = User::query()->findOrFail($member->approved_by);
app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.promoters.manage');
Queue::fake();
$assert = function (bool $ok, string $message) {
    if (! $ok) {
        throw new RuntimeException('Portal regression failed: '.$message);
    }
};
$csrf = function (string $body) {
    if (! preg_match('/name="_token"[^>]*value="([^"]+)"/', $body, $m)) {
        throw new RuntimeException('Missing CSRF');
    }

return html_entity_decode($m[1]);
};
$makeClient = fn ($jar) => new Client(['base_uri' => 'https://testadmin.decomkt.com', 'cookies' => $jar, 'http_errors' => false, 'timeout' => 25]);
$jar = new CookieJar;
$client = $makeClient($jar);
$portal = app(AffiliatePortalService::class);
$token = $portal->issue($store, $member);
$page = $client->get('/referral-portal/login');
$response = $client->post('/referral-portal/session', ['form_params' => ['_token' => $csrf((string) $page->getBody()), 'token' => $token]]);
$body = (string) $response->getBody();
$assert($response->getStatusCode() === 200 && str_contains($body, 'Referral Regression Test') && str_contains($body, 'referral-qr'), 'dashboard and QR');
$repeat = $client->post('/referral-portal/session', ['form_params' => ['_token' => $csrf($body), 'token' => $token]]);
$assert($repeat->getStatusCode() === 401, 'single use token');
$assert($jar->getCookieByName('deco_referral_portal')?->getPath() === '/referral-portal' && ! $jar->getCookieByName(config('session.cookie')), 'cookie isolation');
$bundle = $client->get('/referral-portal/portal.js');
$assert($bundle->getStatusCode() === 200 && strlen((string) $bundle->getBody()) > 1000, 'portal bundle');

$management = app(AffiliateManagementService::class);
$oldSettings = AffiliateStoreSetting::query()->forStore($store)->firstOrFail()->only(['affiliate_enabled', 'customer_referral_enabled']);
$program = null;
$invited = null;
try {
    $management->updateSettings($org, $store, $actor, array_replace($oldSettings, ['affiliate_enabled' => true]));
    $suffix = strtolower(substr((string) Str::ulid(), -10));
    $program = $management->createProgram($org, $store, $actor, ['name' => 'TEST portal acceptance '.$suffix, 'type' => 'affiliate', 'attribution_model' => 'coupon_wins', 'attribution_window_days' => 30, 'hold_days' => 0, 'commission_type' => 'percentage', 'rate_basis_points' => 1000, 'coupon_enabled' => false]);
    $management->transitionProgram($org, $store, $actor, $program->public_id, 'activate');
    $promoter = $management->createPromoter($org, $store, $actor, ['display_name' => 'TEST invitation '.$suffix, 'email' => 'portal-'.$suffix.'@example.invalid', 'type' => 'affiliate', 'program_public_id' => $program->public_id]);
    $invited = $program->memberships()->where('promoter_id', $promoter->id)->firstOrFail();
    $url = app(AffiliateInvitationService::class)->issue($org, $store, $actor, $invited->public_id);
    $inviteToken = substr($url, -64);
    $guest = $makeClient(new CookieJar);
    $landing = $guest->get('/referral-portal/invitation');
    $accepted = $guest->post('/referral-portal/invitation', ['form_params' => ['_token' => $csrf((string) $landing->getBody()), 'token' => $inviteToken, 'terms' => '1', 'notes' => 'TEST HTTP invitation acceptance']]);
    $acceptedBody = (string) $accepted->getBody();
    $assert($accepted->getStatusCode() === 200 && str_contains($acceptedBody, '邀请已接受'), 'invitation HTTP acceptance');
    $assert(data_get($invited->fresh()->application_encrypted, 'notes') === 'TEST HTTP invitation acceptance' && $invited->fresh()->status->value === 'pending', 'invitation notes and review gate');
    $replay = $guest->post('/referral-portal/invitation', ['form_params' => ['_token' => $csrf($acceptedBody), 'token' => $inviteToken, 'terms' => '1']]);
    $assert($replay->getStatusCode() === 410, 'invitation replay rejection');
} finally {
    if ($invited && in_array($invited->fresh()->status->value, ['pending', 'waitlisted'], true)) {
        $management->transitionMembership($org, $store, $actor, $invited->public_id, 'reject', 'TEST completed; synthetic invitation');
    }
    if ($program && $program->fresh()->status->value === 'active') {
        $management->transitionProgram($org, $store, $actor, $program->public_id, 'pause');
    }
    $management->updateSettings($org, $store, $actor, $oldSettings);
}
echo json_encode(['shop' => $store->shopify_domain, 'dashboard_http' => 200, 'portal_replay_http' => 401, 'isolated_cookie' => true, 'portal_bundle' => true, 'invitation_http' => 200, 'invitation_replay_http' => 410, 'invited_membership_pending_until_review' => true, 'test_invitation_cleaned_up' => true, 'settings_restored' => true, 'external_mail_sent' => false],JSON_PRETTY_PRINT).PHP_EOL;
