<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueCodexApiTokenRequest;
use App\Http\Requests\RevokeCodexApiTokenRequest;
use App\Http\Resources\CodexApiTokenResource;
use App\Models\CodexApiToken;
use App\Services\Codex\CodexApiTokenManagementService;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CodexApiTokenController extends Controller
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CodexApiTokenManagementService $tokens,
    ) {}

    public function index(Request $request): Response
    {
        $organization = $this->currentOrganization->require();
        abort_unless($request->user()->hasPermission('codex.tokens.view', $organization), 403);

        $tokens = CodexApiToken::query()
            ->where('organization_id', $organization->getKey())
            ->with(['user:id,name,email', 'issuer:id,name', 'oauthRefreshToken'])
            ->latest()
            ->paginate(20)
            ->withQueryString();
        $users = $organization->users()
            ->where('users.status', 'active')
            ->whereNotNull('users.email_verified_at')
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.email']);

        return Inertia::render('CodexTokens/Index', [
            'tokens' => CodexApiTokenResource::collection($tokens),
            'users' => $users->map(fn ($user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ])->values(),
            'abilities' => $this->tokens->abilityCatalog(),
            'canManage' => $request->user()->hasPermission('codex.tokens.manage', $organization),
        ]);
    }

    public function store(IssueCodexApiTokenRequest $request): JsonResponse
    {
        $organization = $this->currentOrganization->require();
        $validated = $request->validated();
        $recipient = $organization->users()
            ->where('users.status', 'active')
            ->whereKey((int) $validated['user_id'])
            ->firstOrFail();
        $issued = $this->tokens->issueForAdministrator(
            $request->user(),
            $recipient,
            $organization,
            $validated['name'],
            $validated['abilities'],
            (int) $validated['expires_in_days'],
            $validated['idempotency_key'],
        );

        return response()->json([
            'data' => (new CodexApiTokenResource($issued['token']->load(['user:id,name,email', 'issuer:id,name', 'oauthRefreshToken'])))->resolve($request),
            'plain_text_token' => $issued['plain_text_token'],
            'idempotent_replay' => $issued['replayed'],
        ], $issued['replayed'] ? 200 : 201);
    }

    public function destroy(RevokeCodexApiTokenRequest $request, CodexApiToken $codexApiToken): JsonResponse
    {
        $organization = $this->currentOrganization->require();
        $revoked = $this->tokens->revokeForAdministrator(
            $request->user(),
            $organization,
            $codexApiToken,
        );

        return response()->json([
            'data' => (new CodexApiTokenResource($revoked['token']->load(['user:id,name,email', 'issuer:id,name', 'oauthRefreshToken'])))->resolve($request),
            'idempotent_replay' => $revoked['replayed'],
        ]);
    }
}
