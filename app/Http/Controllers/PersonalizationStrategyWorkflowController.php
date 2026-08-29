<?php

namespace App\Http\Controllers;

use App\Exceptions\PersonalizationException;
use App\Models\Organization;
use App\Models\PersonalizationRecommendationStrategy;
use App\Models\PersonalizationStrategyVersion;
use App\Models\Store;
use App\Services\Personalization\PersonalizationGlobalSettingsService;
use App\Services\Personalization\PersonalizationStrategyWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonalizationStrategyWorkflowController extends Controller
{
    public function __construct(
        private PersonalizationStrategyWorkflowService $workflow,
        private PersonalizationGlobalSettingsService $globalSettings,
    ) {}

    public function createDraft(Request $request, Organization $organization, Store $store): JsonResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        return $this->run(fn () => $this->workflow->createDraft($store, $request->user(), $values['idempotency_key']), 201);
    }

    public function editor(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): JsonResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.view');

        return $this->run(fn () => $this->workflow->editor($store, $strategy, $request->user()));
    }

    public function autosave(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): JsonResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'draft' => ['required', 'array'],
        ]);

        return $this->run(fn () => $this->workflow->autosave($store, $strategy, $request->user(), $values));
    }

    public function publish(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): JsonResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'lock_version' => ['required', 'integer', 'min:1'],
            'confirm_replacements' => ['required', 'boolean'],
        ]);

        return $this->run(fn () => $this->workflow->publish($store, $strategy, $request->user(), $values));
    }

    public function duplicate(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): JsonResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate(['idempotency_key' => ['required', 'uuid']]);

        return $this->run(fn () => $this->workflow->duplicate($store, $strategy, $request->user(), $values['idempotency_key']), 201);
    }

    public function disable(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): JsonResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');

        return $this->run(fn () => $this->workflow->disable($store, $strategy, $request->user()));
    }

    public function recycle(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): JsonResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');

        return $this->run(function () use ($store, $strategy, $request): array {
            $this->workflow->recycle($store, $strategy, $request->user());

            return ['recycled' => true, 'retention_days' => PersonalizationStrategyWorkflowService::RECYCLE_DAYS];
        });
    }

    public function restore(Request $request, Organization $organization, Store $store, string $strategyUuid): JsonResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');

        return $this->run(fn () => $this->workflow->restore($store, $strategyUuid, $request->user()));
    }

    public function restoreVersion(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
        PersonalizationStrategyVersion $version,
    ): JsonResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'confirm_replacements' => ['required', 'boolean'],
        ]);

        return $this->run(fn () => $this->workflow->restoreVersion(
            $store,
            $strategy,
            $version,
            $request->user(),
            $values['idempotency_key'],
            $values['confirm_replacements'],
        ));
    }

    public function preview(
        Request $request,
        Organization $organization,
        Store $store,
        PersonalizationRecommendationStrategy $strategy,
    ): JsonResponse {
        $this->assertUserScope($request, $organization, $store, 'personalization.view');
        $values = $request->validate([
            'seed_product_id' => ['nullable', 'string', 'max:255'],
            'cart_product_ids' => ['array', 'max:20'],
            'cart_product_ids.*' => ['string', 'max:255'],
            'purchased_product_ids' => ['array', 'max:50'],
            'purchased_product_ids.*' => ['string', 'max:255'],
        ]);

        return $this->run(fn () => $this->workflow->preview($store, $strategy, $request->user(), $values));
    }

    public function saveGlobalSettings(Request $request, Organization $organization, Store $store): JsonResponse
    {
        $this->assertUserScope($request, $organization, $store, 'personalization.manage');
        $values = $request->validate([
            'default_locale' => ['required', 'in:zh-CN,en'],
            'copy' => ['required', 'array'],
            'copy.recommendation_heading' => ['required', 'string', 'max:120'],
            'copy.add_button' => ['required', 'string', 'max:60'],
            'copy.checkout_heading' => ['required', 'string', 'max:120'],
        ]);

        return $this->run(fn () => $this->globalSettings->save($store, $request->user(), $values));
    }

    private function assertUserScope(Request $request, Organization $organization, Store $store, string $permission): void
    {
        abort_unless($store->organization_id === $organization->id, 403);
        abort_unless($request->user()->canAccessStore($store), 403);
        abort_unless($request->user()->hasPermission($permission, $organization, $store), 403);
    }

    private function run(callable $action, int $status = 200): JsonResponse
    {
        try {
            return response()->json(['data' => $action()], $status);
        } catch (PersonalizationException $exception) {
            return response()->json(['error' => [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ]], $exception->statusCode);
        }
    }
}
