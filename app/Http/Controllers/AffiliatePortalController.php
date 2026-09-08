<?php

namespace App\Http\Controllers;

use App\Domain\ReferralAffiliate\Models\AffiliateProgram;
use App\Domain\ReferralAffiliate\Services\AffiliateInvitationService;
use App\Domain\ReferralAffiliate\Services\AffiliatePortalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AffiliatePortalController extends Controller
{
    public function __construct(private AffiliatePortalService $portal) {}

    private function render(string $page, array $data = [])
    {
        return response(view()->file(base_path('shopify-apps/deco-referral/resources/portal.blade.php'), ['page' => $page] + $data))
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Content-Type-Options', 'nosniff');
    }

    private function validatePortal(Request $request, array $rules): array
    {
        try {
            return $request->validate($rules);
        } catch (ValidationException $exception) {
            $exception->redirectTo(match ($request->path()) {
                'referral-portal/invitation' => '/referral-portal/invitation',
                'referral-portal/profile' => '/referral-portal#profile',
                default => '/referral-portal',
            });
            throw $exception;
        }
    }

    public function index(Request $r)
    {
        $store = $this->portal->store();
        if (! $r->session()->has('affiliate_member')) {
            return $this->render('welcome', ['store' => $store, 'loginPrograms' => AffiliateProgram::query()->forOrganization($store->organization_id)->forStore($store)->whereIn('status', ['active', 'paused'])->limit(100)->get(), 'programs' => AffiliateProgram::query()->forOrganization($store->organization_id)->forStore($store)->where('status', 'active')->limit(100)->get()]);
        }
        $member = $this->portal->member($store, (int) $r->session()->get('affiliate_member'));

        return $this->render('dashboard', $this->portal->dashboard($store, $member->id));
    }

    public function apply(Request $r)
    {
        $v = $this->validatePortal($r, ['program' => ['required', 'string', 'size:26'], 'name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email:rfc', 'max:254'],
            'country' => ['nullable', 'string', 'max:80'], 'website' => ['nullable', 'url:http,https', 'max:500'], 'method' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'], 'terms' => ['accepted']]);
        $this->portal->apply($this->portal->store(), $v);

        return redirect('/referral-portal')->with('portal_message', '申请已收到，请等待店铺审核。');
    }

    public function requestLogin(Request $r)
    {
        $v = $this->validatePortal($r, ['email' => ['required', 'email:rfc', 'max:254'], 'program' => ['required', 'string', 'size:26']]);
        $this->portal->requestLogin($this->portal->store(), $v['email'], $v['program']);

        return redirect('/referral-portal')->with('portal_message', '如果该邮箱已获批准，将收到一次性登录链接。');
    }

    public function invitation()
    {
        return $this->render('invitation');
    }

    public function acceptInvitation(Request $r)
    {
        $v = $this->validatePortal($r, ['token' => ['required', 'string', 'size:64'], 'terms' => ['accepted'], 'website' => ['nullable', 'url:http,https', 'max:500'], 'notes' => ['nullable', 'string', 'max:2000']]);
        app(AffiliateInvitationService::class)->accept($this->portal->store(), $v['token'], $v);

        return redirect('/referral-portal')->with('portal_message', '邀请已接受，请等待店铺审核。');
    }

    public function login()
    {
        return $this->render('login');
    }

    public function session(Request $r)
    {
        $v = $this->validatePortal($r, ['token' => ['required', 'string', 'size:64']]);
        $member = $this->portal->consume($this->portal->store(), $v['token']);
        $r->session()->regenerate();
        $r->session()->put(['affiliate_member' => $member->id, 'affiliate_verified_at' => now()->timestamp]);

        return redirect('/referral-portal');
    }

    public function logout(Request $r)
    {
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/referral-portal');
    }

    public function profile(Request $r)
    {
        $store = $this->portal->store();
        $member = $this->portal->member($store, (int) $r->session()->get('affiliate_member'));
        abort_unless((int) $r->session()->get('affiliate_verified_at') >= now()->subMinutes(15)->timestamp, 403, '请先使用新登录链接验证身份，再修改付款资料。');
        $v = $this->validatePortal($r, ['country' => ['nullable', 'string', 'max:80'], 'payment_method' => ['required', 'in:paypal,bank,other'],
            'payment_reference' => ['required', 'string', 'max:500']]);
        $this->portal->updateProfile($store, $member->id, $v);

        return redirect('/referral-portal#profile')->with('portal_message', '资料已保存。');
    }

    public function asset(Request $r, string $asset)
    {
        $store = $this->portal->store();
        $this->portal->member($store, (int) $r->session()->get('affiliate_member'));
        $row = DB::table('affiliate_assets')->where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('public_id', $asset)->first();
        abort_unless($row, 404);

        return Storage::disk('local')->download($row->path, $row->title, ['X-Content-Type-Options' => 'nosniff']);
    }
}
