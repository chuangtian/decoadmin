<?php

namespace App\Http\Controllers;

use App\Domain\ReferralAffiliate\Services\AffiliateTrackingService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;

class AffiliateTrackingController extends Controller
{
    public function __invoke(Request $request, AffiliateTrackingService $tracking, string $link): RedirectResponse
    {
        return $tracking->redirect($request, $link);
    }
}
