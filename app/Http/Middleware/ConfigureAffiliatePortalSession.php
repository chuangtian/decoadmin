<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ConfigureAffiliatePortalSession
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('referral-portal') && ! $request->is('referral-portal/*')) {
            return $next($request);
        }
        $original = ['session.cookie' => config('session.cookie'), 'session.path' => config('session.path'), 'session.same_site' => config('session.same_site')];
        config(['session.cookie' => 'deco_referral_portal', 'session.path' => '/referral-portal', 'session.same_site' => 'lax']);
        app('session')->forgetDrivers();
        try {
            $response = $next($request);
            $messages = [
                401 => ['登录链接已失效', '请返回门户重新申请登录链接，并在收到后 15 分钟内使用。'],
                403 => ['需要重新验证身份', '请返回门户使用新登录链接验证身份；若仍无法操作，请联系店铺核对权限。'],
                404 => ['内容暂不可用', '该记录不存在或当前账号无法访问，请返回门户或联系店铺。'],
                409 => ['当前状态无法继续', '计划或成员状态已变化，请联系店铺核对后再操作。'],
                410 => ['邀请已使用或失效', '此邀请不能再次使用。请联系店铺重新邀请；已提交的申请请等待审核。'],
                419 => ['页面已过期', '请返回门户刷新页面后重试。'],
                422 => ['请检查填写内容', '请返回门户检查输入内容后重试。'],
                429 => ['操作过于频繁', '请稍后再试，不要连续重复提交。'],
                503 => ['服务暂时不可用', '暂时无法完成此操作，请稍后重试或联系店铺。'],
            ];
            if (! $request->expectsJson() && isset($messages[$response->getStatusCode()])) {
                [$title, $message] = $messages[$response->getStatusCode()];
                $response->setContent(view()->file(base_path('shopify-apps/deco-referral/resources/portal-error.blade.php'), compact('title', 'message'))->render());
                $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
                $response->headers->set('Cache-Control', 'no-store');
                $response->headers->set('Referrer-Policy', 'no-referrer');
                $response->headers->set('X-Content-Type-Options', 'nosniff');
                $response->headers->remove('Content-Length');
            }

            return $response;
        } finally {
            config($original);
            app('session')->forgetDrivers();
        }
    }
}
