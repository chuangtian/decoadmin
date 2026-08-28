<?php

namespace App\Http\Controllers;

use App\Http\Resources\WebhookEventDetailResource;
use App\Http\Resources\WebhookEventResource;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Shopify\ShopifyWebhookRetryService;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WebhookEventController extends Controller
{
    public function __construct(private CurrentOrganization $currentOrganization) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', WebhookEvent::class);
        $organization = $this->currentOrganization->require();
        $storeIds = $this->accessibleStoreIds($request->user(), $organization);
        $filters = $request->validate([
            'topic' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', 'max:30'],
            'store_id' => ['nullable', 'integer'],
        ]);
        $topic = trim((string) ($filters['topic'] ?? ''));
        $status = trim((string) ($filters['status'] ?? ''));
        $storeId = (int) ($filters['store_id'] ?? 0);

        if ($storeId && ! in_array($storeId, $storeIds, true)) {
            abort(403);
        }

        $events = WebhookEvent::query()
            ->whereBelongsTo($organization)
            ->whereIn('store_id', $storeIds)
            ->when($topic !== '', fn ($query) => $query->where('topic', 'like', "%{$topic}%"))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->when($storeId > 0, fn ($query) => $query->where('store_id', $storeId))
            ->select([
                'id', 'webhook_id', 'organization_id', 'store_id', 'topic', 'status',
                'processing_result', 'attempts', 'received_at', 'processed_at',
            ])
            ->with('store:id,organization_id,name,shopify_domain')
            ->latest('received_at')
            ->paginate(25)
            ->withQueryString();

        $stores = $organization->stores()
            ->whereIn('id', $storeIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('Webhooks/Index', [
            'events' => WebhookEventResource::collection($events),
            'filters' => ['topic' => $topic, 'status' => $status, 'store_id' => $storeId ?: null],
            'stores' => $stores,
            'statuses' => ['received', 'queued', 'processing', 'processed', 'failed', 'retrying'],
        ]);
    }

    public function show(WebhookEvent $webhookEvent): Response
    {
        $this->authorize('view', $webhookEvent);

        return Inertia::render('Webhooks/Show', [
            'event' => new WebhookEventDetailResource($webhookEvent->load([
                'store:id,organization_id,name,shopify_domain',
                'app:id,name,handle',
            ])),
            'canRetry' => request()->user()->can('retry', $webhookEvent) && $webhookEvent->status === 'failed',
        ]);
    }

    public function retry(
        Request $request,
        WebhookEvent $webhookEvent,
        ShopifyWebhookRetryService $retryService,
    ): RedirectResponse {
        $this->authorize('retry', $webhookEvent);
        $retryService->retry($webhookEvent, $request->user());

        return back()->with('success', 'Webhook 事件已重新加入处理队列。');
    }

    /** @return list<int> */
    private function accessibleStoreIds(User $user, Organization $organization): array
    {
        return $user->isSuperAdmin()
            ? $organization->stores()->pluck('id')->all()
            : $user->stores()
                ->where('stores.organization_id', $organization->getKey())
                ->pluck('stores.id')
                ->all();
    }
}
