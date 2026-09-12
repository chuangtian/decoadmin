<?php

namespace DecoReviews\Services;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use DecoReviews\Models\Invitation;
use DecoReviews\Models\Review;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvitationService
{
    public function __construct(private ReviewService $reviews) {}

    public function schedule(Store $store, User $user, int $orderId): int
    {
        $this->reviews->authorize($user, $store, true);
        abort_unless($user->hasPermission('orders.view', $store->organization, $store), 403);

        return DB::transaction(function () use ($store, $user, $orderId) {
            $order = Order::where('organization_id', $store->organization_id)->where('store_id', $store->id)->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($order->cancelled_at || in_array($order->financial_status, ['refunded', 'voided']) || ! filter_var($order->email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['order_id' => '订单已取消、已退款或没有可用邮箱，不能邀评。']);
            }
            $settings = $this->reviews->settings($store);
            $hash = $this->reviews->emailHash($store, $order->email);
            if ($this->suppressed($store, $hash)) {
                throw ValidationException::withMessages(['order_id' => '该收件人已退订邀评。']);
            }
            $products = $order->items()->where('current_quantity', '>', 0)->whereHas('product', fn ($q) => $q->where('organization_id', $store->organization_id)->where('store_id', $store->id))->pluck('product_id')->unique();
            if ($products->isEmpty()) {
                throw ValidationException::withMessages(['order_id' => '订单中没有可邀评的有效商品。']);
            }
            $created = 0;
            foreach ($products as $productId) {
                $invite = Invitation::firstOrCreate(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'order_id' => $order->id, 'product_id' => $productId], [
                    'uuid' => (string) Str::uuid(), 'email' => $order->email, 'email_hash' => $hash,
                    // Core order snapshots do not contain fulfillment timestamps or consent.
                    // Verification is required; never mislabel a scheduled record as an email sent.
                    'status' => 'verification_required', 'error_code' => 'FULFILLMENT_AND_CONSENT_REQUIRED',
                    'due_at' => null, 'expires_at' => now()->addDays(180),
                ]);
                if ($invite->wasRecentlyCreated) {
                    $created++;
                }
            }
            $this->reviews->audit($store, $user, 'invitations.created', null, ['count' => $created, 'order_id' => $order->id]);

            return $created;
        });
    }

    public function cancel(Store $store, User $user, string $uuid): void
    {
        $this->reviews->authorize($user, $store, true);
        DB::transaction(function () use ($store, $user, $uuid) {
            $invite = Invitation::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            if ($invite->status === 'sending') {
                throw ValidationException::withMessages(['invitation' => '邮件正在发送，不能保证撤回，请等待发送结果。']);
            }
            if ($invite->status === 'completed') {
                throw ValidationException::withMessages(['invitation' => '已收到评价的邀评不能取消。']);
            }
            $invite->update(['status' => 'cancelled', 'due_at' => null]);
            $this->reviews->audit($store, $user, 'invitation.cancelled', null, ['invitation' => $uuid]);
        });
    }

    public function suppressed(Store $store, string $hash): bool
    {
        return DB::table('deco_review_suppressions')->where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('email_hash', $hash)->exists();
    }

    public function link(Invitation $invite): string
    {
        return URL::temporarySignedRoute('deco-reviews.write', $invite->expires_at, ['invitation' => $invite->uuid]);
    }

    public function resolve(string $uuid): array
    {
        $invite = Invitation::where('uuid', $uuid)->firstOrFail();
        $store = Store::where('organization_id', $invite->organization_id)->whereKey($invite->store_id)->firstOrFail();
        $this->reviews->active($store);
        abort_unless($invite->expires_at->isFuture() && ! in_array($invite->status, ['cancelled', 'unsubscribed']), 410);

        return [$store, $invite];
    }

    public function submit(string $uuid, array $input, array $files): Review
    {
        return DB::transaction(function () use ($uuid, $input, $files) {
            [$store, $invite] = $this->resolve($uuid);
            $invite = Invitation::whereKey($invite->id)->lockForUpdate()->firstOrFail();
            if ($invite->status === 'completed') {
                return $this->reviews->scoped($store)->whereKey($invite->review_id)->firstOrFail();
            }
            abort_unless(in_array($invite->status, ['sent', 'scheduled']), 409);
            $order = Order::where('organization_id', $store->organization_id)->where('store_id', $store->id)->whereKey($invite->order_id)->firstOrFail();
            if ($order->cancelled_at || in_array($order->financial_status, ['refunded', 'voided']) || $this->suppressed($store, $invite->email_hash)) {
                abort(410);
            }
            $review = $this->reviews->create($store, array_merge($input, ['kind' => 'product', 'product_id' => $invite->product_id, 'author_email' => $invite->email]), $files, null,
                ['source' => 'email', 'verified_source' => 'order', 'order_id' => $invite->order_id]);
            $invite->update(['review_id' => $review->id, 'status' => 'completed', 'completed_at' => now(), 'due_at' => null]);

            return $review;
        });
    }

    public function unsubscribe(string $uuid): void
    {
        [$store, $invite] = $this->resolve($uuid);
        DB::transaction(function () use ($store, $invite) {
            DB::table('deco_review_suppressions')->insertOrIgnore(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'email_hash' => $invite->email_hash, 'created_at' => now(), 'updated_at' => now()]);
            Invitation::where('organization_id', $store->organization_id)->where('store_id', $store->id)->where('email_hash', $invite->email_hash)->whereNotIn('status', ['completed', 'cancelled', 'sending'])
                ->update(['status' => 'unsubscribed', 'due_at' => null]);
            $this->reviews->audit($store, null, 'invitation.unsubscribed');
        });
    }
}
