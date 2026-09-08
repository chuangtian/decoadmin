<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateClick;
use App\Domain\ReferralAffiliate\Models\AffiliateLink;
use App\Domain\ReferralAffiliate\Models\AffiliateStoreSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AffiliateTrackingService
{
    public function redirect(Request $request, string $publicId): RedirectResponse
    {
        $link = AffiliateLink::query()->where('public_id', $publicId)
            ->where('status', 'active')
            ->with(['membership.program', 'membership.store', 'membership.promoter'])
            ->firstOrFail();
        $membership = $link->membership;
        app(AffiliateShopGuard::class)->store($membership->store);
        abort_unless((int) $link->store_id === (int) $membership->store_id
            && (int) $link->organization_id === (int) $membership->store->organization_id
            && (int) $membership->program->store_id === (int) $link->store_id
            && $membership->promoter?->status === 'active', 404);
        abort_unless($membership->status->value === 'approved'
            && $membership->program?->status->value === 'active'
            && $membership->store?->status === 'active'
            && AffiliateStoreSetting::query()->forStore($link->store_id)->where($membership->program->type->value === 'advocate' ? 'customer_referral_enabled' : 'affiliate_enabled', true)->exists(), 404);

        $visitorToken = hash('sha256', Str::random(64));
        $referrerHost = $this->host($request->headers->get('referer'));
        $click = AffiliateClick::query()->create([
            'organization_id' => $link->organization_id,
            'store_id' => $link->store_id,
            'link_id' => $link->id,
            'membership_id' => $membership->id,
            'visitor_token' => $visitorToken,
            'referrer_host' => $referrerHost,
            'ip_hash' => $this->dailyHash($request->ip()),
            'ua_hash' => $this->hash($request->userAgent()),
            'occurred_at' => now(),
        ]);
        $token = app(AffiliateTrackingTokenService::class)->issue($link, $click);
        $path = (string) $request->query('to', $link->target_path);
        abort_unless(strlen($path) <= 1000 && str_starts_with($path, '/') && ! str_starts_with($path, '//')
            && ! str_contains(rawurldecode($path), '\\') && ! preg_match('/[\x00-\x1f]/', rawurldecode($path)), 422);
        $fragment = parse_url($path, PHP_URL_FRAGMENT);
        $path = explode('#', $path, 2)[0];
        $target = 'https://'.$membership->store->shopify_domain.$path;
        $separator = str_contains($target, '?') ? '&' : '?';

        return redirect()->away($target.$separator.http_build_query([
            'ref' => $link->referral_code,
            'deco_aff' => $token,
            'utm_source' => mb_substr((string) $request->query('utm_source', data_get($link->utm, 'source', 'affiliate')), 0, 100),
            'utm_medium' => (string) data_get($link->utm, 'medium', 'referral'),
        ]).($fragment !== null ? '#'.rawurlencode($fragment) : ''), 302, ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store']);
    }

    private function dailyHash(?string $value): ?string
    {
        return $value ? hash_hmac('sha256', now()->toDateString().'|'.$value, (string) config('app.key')) : null;
    }

    private function hash(?string $value): ?string
    {
        return $value ? hash_hmac('sha256', $value, (string) config('app.key')) : null;
    }

    private function host(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $host = parse_url($value, PHP_URL_HOST);

        return is_string($host) ? mb_strtolower(mb_substr($host, 0, 255)) : null;
    }
}
