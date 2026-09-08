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
            return $next($request);
        } finally {
            config($original);
            app('session')->forgetDrivers();
        }
    }
}
