<?php

namespace App\Http\Controllers;

use App\Exceptions\CodexOAuthException;
use App\Services\Codex\CodexApiTokenManagementService;
use App\Services\Codex\CodexOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class CodexOAuthAuthorizationController extends Controller
{
    public function show(
        Request $request,
        CodexOAuthService $oauth,
        CodexApiTokenManagementService $tokens,
    ): Response|SymfonyResponse {
        try {
            $context = $oauth->authorizationContext($request->user(), $request->query());
        } catch (CodexOAuthException $exception) {
            return $this->error($exception);
        }
        $catalog = collect($tokens->abilityCatalog())->keyBy('slug');

        return Inertia::render('CodexOAuth/Authorize', [
            'client' => [
                'name' => $context['client']->client_name,
                'id' => $context['client']->client_id,
            ],
            'abilities' => collect($context['abilities'])->map(fn (string $slug): array => [
                'slug' => $slug,
                'label' => data_get($catalog->get($slug), 'label', $slug),
                'description' => data_get($catalog->get($slug), 'description', ''),
                'group' => data_get($catalog->get($slug), 'group', 'read'),
            ])->values()->all(),
            'organizations' => $context['organizations']->map(fn ($organization): array => [
                'id' => $organization->getKey(),
                'name' => $organization->name,
            ])->values()->all(),
            'authorizationRequest' => $context['request'],
        ]);
    }

    public function store(Request $request, CodexOAuthService $oauth): RedirectResponse|SymfonyResponse
    {
        $values = $request->validate([
            'decision' => ['required', 'in:approve,deny'],
            'organization_id' => ['nullable', 'integer', 'min:1'],
            'client_id' => ['required', 'string', 'max:120'],
            'redirect_uri' => ['required', 'string', 'max:1024'],
            'response_type' => ['required', 'string', 'max:40'],
            'scope' => ['nullable', 'string', 'max:1024'],
            'state' => ['required', 'string', 'max:2048'],
            'code_challenge' => ['required', 'string', 'max:128'],
            'code_challenge_method' => ['required', 'string', 'max:20'],
            'resource' => ['required', 'string', 'max:1024'],
        ]);

        try {
            $redirect = $values['decision'] === 'deny'
                ? $oauth->denyAuthorization($values)
                : $oauth->approveAuthorization($request->user(), (int) ($values['organization_id'] ?? 0), $values);
        } catch (CodexOAuthException $exception) {
            return $this->error($exception);
        }

        return redirect()->away($this->appendQuery($redirect['redirect_uri'], $redirect['parameters']));
    }

    private function error(CodexOAuthException $exception): SymfonyResponse
    {
        return Inertia::render('CodexOAuth/Error', [
            'code' => $exception->oauthError,
            'message' => $exception->getMessage(),
        ])->toResponse(request())->setStatusCode($exception->status);
    }

    /** @param array<string, string> $parameters */
    private function appendQuery(string $url, array $parameters): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }
}
