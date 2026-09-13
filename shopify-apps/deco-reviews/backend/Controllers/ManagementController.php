<?php

namespace DecoReviews\Controllers;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Store;
use DecoReviews\Models\ImportBatch;
use DecoReviews\Models\Invitation;
use DecoReviews\Models\Media;
use DecoReviews\Services\FormService;
use DecoReviews\Services\ImportService;
use DecoReviews\Services\InvitationEmail;
use DecoReviews\Services\InvitationService;
use DecoReviews\Services\ReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ManagementController
{
    public function __construct(private ReviewService $reviews) {}

    private function authorize(Request $request, Organization $organization, Store $store, bool $write = false): void
    {
        abort_unless((int) $store->organization_id === (int) $organization->id, 404);
        $this->reviews->authorize($request->user(), $store, $write);
    }

    public function index(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store);
        $filters = $request->validate(['tab' => 'nullable|in:overview,reviews,invitations,settings,imports,widgets,form',
            'kind' => 'nullable|in:product,store', 'status' => 'nullable|in:published,pending,unpublished',
            'rating' => 'nullable|integer|between:1,5', 'product_id' => 'nullable|integer|min:1',
            'media' => 'nullable|in:with,without', 'source' => 'nullable|in:merchant,email,import,organic',
            'verified' => 'nullable|in:order,none', 'featured' => 'nullable|boolean', 'incentivized' => 'nullable|boolean',
            'reply' => 'nullable|in:with,without', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date|after_or_equal:date_from',
            'q' => 'nullable|string|max:120', 'sort' => 'nullable|in:newest,oldest,rating_desc,rating_asc', 'page' => 'nullable|integer|min:1|max:500']);
        $settings = $this->reviews->settings($store);
        $rows = $this->reviews->filtered($store, $filters)->with(['product', 'media'])->paginate(15)->withQueryString();
        $rows->through(fn ($review) => $this->reviews->serialize($review, $store, true, $settings));
        $summary = $this->reviews->scoped($store)->selectRaw("count(*) total, sum(case when status = 'published' then 1 else 0 end) published, sum(case when status = 'pending' then 1 else 0 end) pending, avg(rating) average")->first();
        $invitations = Invitation::where('organization_id', $organization->id)->where('store_id', $store->id)->with(['product', 'order'])->latest('id')->paginate(15)->withQueryString();
        $invitations->through(fn ($invite) => ['uuid' => $invite->uuid, 'order_id' => $invite->order_id,
            'order_number' => $invite->order?->order_number, 'product_title' => $invite->product?->title,
            'status' => $invite->status, 'due_at' => $invite->due_at?->toIso8601String(), 'sent_at' => $invite->sent_at?->toIso8601String(),
            'reminder_sent_at' => $invite->reminder_sent_at?->toIso8601String(),
            'completed_at' => $invite->completed_at?->toIso8601String(),
            'created_at' => $invite->created_at->toIso8601String(), 'error_code' => $invite->error_code]);

        return Inertia::render('DecoReviews/Index', [
            'organization' => $organization->only('id', 'name'), 'store' => $store->only('id', 'name'),
            'baseUrl' => route('deco-reviews.index', [$organization, $store]), 'canManage' => $request->user()->hasPermission('products.update', $organization, $store),
            'tab' => $filters['tab'] ?? 'overview', 'filters' => $filters, 'reviews' => $rows, 'invitations' => $invitations,
            'stats' => ['total' => (int) $summary->total, 'published' => (int) $summary->published, 'pending' => (int) $summary->pending, 'average' => round((float) $summary->average, 2),
                'media' => $this->reviews->scoped($store)->whereHas('media')->count(), 'invites_sent' => Invitation::where('organization_id', $organization->id)->where('store_id', $store->id)->whereNotNull('sent_at')->count()],
            'products' => Product::where('organization_id', $organization->id)->where('store_id', $store->id)->orderBy('title')->limit(500)->get(['id', 'title']),
            'settings' => $settings,
            'formConfig' => app(FormService::class)->configuration($store),
            'imports' => ImportBatch::where('organization_id', $organization->id)->where('store_id', $store->id)->latest()->limit(30)->get(['uuid', 'status', 'imported', 'skipped', 'errors', 'created_at', 'undone_at'])
                ->map(fn ($batch) => array_merge($batch->toArray(), ['can_undo' => ! $batch->undone_at && $batch->created_at->gte(now()->subDays(7))])),
        ]);
    }

    public function emailPreview(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store);
        $data = $request->validate(['kind' => 'nullable|in:initial,reminder']);

        return response()->view('deco-reviews::emails.invitation', app(InvitationEmail::class)->content($store, null, $data['kind'] ?? 'initial'))
            ->header('Cache-Control', 'private, no-store')->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'");
    }

    public function create(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store, true);
        $this->reviews->create($store, $request->all(), $request->file('media', []), $request->user());

        return back()->with('success', '评价已保存，按照发布规则处理。');
    }

    public function moderate(Request $request, Organization $organization, Store $store, string $review)
    {
        $this->authorize($request, $organization, $store, true);
        $this->reviews->moderate($store, $request->user(), [$review], $request->all());

        return back()->with('success', '评价已更新。');
    }

    public function bulk(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store, true);
        $values = $request->validate(['ids' => 'required|array|min:1|max:100']);
        $this->reviews->moderate($store, $request->user(), $values['ids'], $request->only('status', 'reason'));

        return back()->with('success', '所选评价已更新。');
    }

    public function settings(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store, true);
        $this->reviews->saveSettings($store, $request->user(), $request->all());

        return back()->with('success', '设置已保存，仅影响后续新评价。');
    }

    public function invitations(Request $request, Organization $organization, Store $store, InvitationService $invites)
    {
        $this->authorize($request, $organization, $store, true);
        $values = $request->validate(['order_id' => 'required|integer|min:1']);
        $count = $invites->schedule($store, $request->user(), $values['order_id']);

        return back()->with('success', "已建立 {$count} 条邀评记录；验证履约与发送条件后才会发信。");
    }

    public function cancel(Request $request, Organization $organization, Store $store, string $invitation, InvitationService $invites)
    {
        $this->authorize($request, $organization, $store, true);
        $invites->cancel($store, $request->user(), $invitation);

        return back()->with('success', '邀评已取消。');
    }

    public function import(Request $request, Organization $organization, Store $store, ImportService $imports)
    {
        $this->authorize($request, $organization, $store, true);
        $request->validate(['file' => 'required|file|max:15360']);
        $batch = $imports->import($store, $request->user(), $request->file('file'));

        return back()->with('success', "导入 {$batch->imported} 条，跳过 {$batch->skipped} 条；错误行见导入记录。");
    }

    public function undo(Request $request, Organization $organization, Store $store, string $batch, ImportService $imports)
    {
        $this->authorize($request, $organization, $store, true);
        $imports->undo($store, $request->user(), $batch);

        return back()->with('success', '导入已撤销，评价已下架并保留审计记录。');
    }

    public function export(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store);
        $filters = $request->validate(['kind' => 'nullable|in:product,store', 'status' => 'nullable|in:published,pending,unpublished',
            'rating' => 'nullable|integer|between:1,5', 'product_id' => 'nullable|integer|min:1',
            'media' => 'nullable|in:with,without', 'source' => 'nullable|in:merchant,email,import,organic',
            'verified' => 'nullable|in:order,none', 'featured' => 'nullable|boolean', 'incentivized' => 'nullable|boolean',
            'reply' => 'nullable|in:with,without', 'date_from' => 'nullable|date', 'date_to' => 'nullable|date|after_or_equal:date_from',
            'q' => 'nullable|string|max:120', 'sort' => 'nullable|in:newest,oldest,rating_desc,rating_asc']);

        return response()->streamDownload(function () use ($store, $filters) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['product_handle', 'rating', 'author_name', 'body', 'reviewed_at', 'title', 'kind', 'status'], ',', '"', '');
            foreach ($this->reviews->filtered($store, $filters)->with('product')->limit(100000)->lazy(250) as $review) {
                $row = [$review->product?->handle ?? '', $review->rating, $review->author_name, $review->body, $review->reviewed_at->toIso8601String(), $review->title ?? '', $review->kind, $review->status];
                $row = array_map(fn ($value) => preg_match('/^[\s]*[=+@\-]/u', (string) $value) ? "'".$value : $value, $row);
                fputcsv($stream, $row, ',', '"', '');
            }
            fclose($stream);
        }, 'deco-reviews.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public function preview(Request $request, Organization $organization, Store $store, StorefrontController $public)
    {
        $this->authorize($request, $organization, $store);

        return response()->json($public->data($store, $request, true))->header('Cache-Control', 'no-store');
    }

    public function widget(Request $request, Organization $organization, Store $store)
    {
        $this->authorize($request, $organization, $store);
        $values = $request->validate(['mode' => 'nullable|in:reviews,stars,carousel,trust,snippets,gallery,video,sidebar,floating']);

        return response()->view('deco-reviews::storefront', ['feedUrl' => route('deco-reviews.preview', [$organization, $store]), 'submitUrl' => null, 'invitationProduct' => null, 'widgetMode' => $values['mode'] ?? 'reviews']);
    }

    public function media(Request $request, Organization $organization, Store $store, string $media)
    {
        $this->authorize($request, $organization, $store);
        $asset = Media::where('organization_id', $organization->id)->where('store_id', $store->id)->where('uuid', $media)->firstOrFail();

        abort_unless(Storage::disk('local')->exists($asset->path), 404);

        return response()->file(Storage::disk('local')->path($asset->path), ['Content-Type' => $asset->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }
}
