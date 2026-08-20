<?php

namespace App\Http\Controllers;

use App\Services\AuditLogQueryService;
use App\Support\CurrentOrganization;
use App\Support\CurrentStore;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __construct(
        private CurrentOrganization $currentOrganization,
        private CurrentStore $currentStore,
        private AuditLogQueryService $auditLogs,
    ) {}

    public function index(Request $request): Response
    {
        $organization = $this->currentOrganization->require();
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', 'max:120'],
            'user_id' => ['nullable', 'integer'],
            'store_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (filled($filters['date_from'] ?? null)
            && filled($filters['date_to'] ?? null)
            && $filters['date_from'] > $filters['date_to']) {
            throw ValidationException::withMessages(['date_to' => '结束日期不能早于开始日期。']);
        }

        $options = $this->auditLogs->filterOptions($request->user(), $organization);
        if (filled($filters['store_id'] ?? null)) {
            validator($filters, [
                'store_id' => [Rule::in(collect($options['stores'])->pluck('id')->all())],
            ])->validate();
        }

        $filterStore = filled($filters['store_id'] ?? null)
            ? $organization->stores()->find((int) $filters['store_id'])
            : $this->currentStore->get();

        return Inertia::render('AuditLogs/Index', [
            'auditLogs' => $this->auditLogs->paginate(
                $request->user(),
                $organization,
                $filters,
                $filterStore?->timezone ?: 'UTC',
            ),
            'summary' => $this->auditLogs->summary($request->user(), $organization),
            'filters' => [
                'search' => trim((string) ($filters['search'] ?? '')),
                'action' => (string) ($filters['action'] ?? ''),
                'user_id' => isset($filters['user_id']) ? (int) $filters['user_id'] : null,
                'store_id' => isset($filters['store_id']) ? (int) $filters['store_id'] : null,
                'date_from' => (string) ($filters['date_from'] ?? ''),
                'date_to' => (string) ($filters['date_to'] ?? ''),
            ],
            'options' => $options,
        ]);
    }

    public function show(Request $request, int $auditLog): Response
    {
        $organization = $this->currentOrganization->require();
        $record = $this->auditLogs->find($request->user(), $organization, $auditLog);

        return Inertia::render('AuditLogs/Show', [
            'auditLog' => $this->auditLogs->detailItem($record),
        ]);
    }
}
