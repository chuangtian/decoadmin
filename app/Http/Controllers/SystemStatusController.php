<?php

namespace App\Http\Controllers;

use App\Services\SystemStatusService;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SystemStatusController extends Controller
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private SystemStatusService $systemStatus,
    ) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('System/Status', [
            'systemStatus' => $this->systemStatus->snapshot(
                $request->user(),
                $this->currentOrganization->require(),
            ),
        ]);
    }
}
