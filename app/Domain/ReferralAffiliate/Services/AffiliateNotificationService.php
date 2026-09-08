<?php

namespace App\Domain\ReferralAffiliate\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateNotificationIntent;
use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Jobs\SendAffiliateNotification;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AffiliateNotificationService
{
    public const DEFAULTS = [
        'promoter.application_received' => ['申请已收到', '您好 {name}，我们已收到您加入 {program} 的申请。'],
        'promoter.approved' => ['申请已通过', '您好 {name}，您已获批准加入 {program}。请在推广者门户申请登录链接。'],
        'promoter.rejected' => ['申请审核结果', '您好 {name}，您加入 {program} 的申请暂未通过。'],
        'conversion.created' => ['新增推广订单', '您好 {name}，{program} 新增一笔推广订单，佣金等待确认。'],
        'commission.available' => ['佣金已可结算', '您好 {name}，您在 {program} 的佣金已通过等待期。'],
        'commission.adjusted' => ['佣金调整通知', '您好 {name}，您在 {program} 的佣金因退款或审核发生调整，请查看门户记录。'],
        'payout.paid' => ['付款已登记', '您好 {name}，您在 {program} 的付款已登记，请查看门户结算记录。'],
        'coupon.disabled' => ['推广优惠码已停用', '您好 {name}，您在 {program} 的优惠码已停用。'],
        'customer.invited' => ['邀请您推荐好友', '您好 {name}，感谢您的购买。邀请您加入 {program}：{invitation_url}'],
        'advocate.reward_issued' => ['推荐奖励已发放', '您好 {name}，您在 {program} 的推荐奖励已发放，请前往门户查看。'],
    ];

    public function templates(Organization $org, Store $store, User $actor): array
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.settings.manage');
        $saved = DB::table('affiliate_message_templates')->where('organization_id', $org->id)->where('store_id', $store->id)->get()->keyBy('key');
        $result = [];
        foreach (self::DEFAULTS as $key => [$subject,$body]) {
            $row = $saved->get($key);
            $result[] = ['key' => $key, 'subject' => $row->subject ?? $subject, 'body' => $row->body ?? $body, 'enabled' => (bool) ($row->enabled ?? false), 'version' => $row->version ?? 1];
        }

        return $result;
    }

    public function save(Organization $org, Store $store, User $actor, string $key, array $values): void
    {
        app(AffiliateShopGuard::class)->actor($org, $store, $actor, 'affiliate.settings.manage');
        abort_unless(isset(self::DEFAULTS[$key]), 422);
        preg_match_all('/\{([^{}]+)\}/', $values['subject'].' '.$values['body'], $variables);
        abort_unless(! array_diff($variables[1], $key === 'customer.invited' ? ['name', 'program', 'invitation_url'] : ['name', 'program']), 422, '请使用此模板支持的变量。');
        DB::transaction(function () use ($org, $store, $actor, $key, $values) {
            Store::query()->whereKey($store->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('affiliate_message_templates')->where('store_id', $store->id)->where('key', $key)->first();
            DB::table('affiliate_message_templates')->updateOrInsert(['store_id' => $store->id, 'key' => $key], ['organization_id' => $org->id,
                'subject' => $values['subject'], 'body' => $values['body'], 'enabled' => $values['enabled'], 'version' => ($row->version ?? 0) + 1, 'locale' => 'en', 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]);
            AuditLog::query()->create(['organization_id' => $org->id, 'store_id' => $store->id, 'user_id' => $actor->id, 'action' => 'affiliate_notification_template_updated', 'metadata' => ['key' => $key, 'enabled' => $values['enabled']]]);
        });
    }

    public function intent(AffiliateProgramMembership $member, string $event, string $dedupe, array $context = []): AffiliateNotificationIntent
    {
        $member->loadMissing('store', 'promoter', 'program');
        app(AffiliateShopGuard::class)->store($member->store);
        abort_unless(isset(self::DEFAULTS[$event]), 422);
        $template = DB::table('affiliate_message_templates')->where('organization_id', $member->organization_id)->where('store_id', $member->store_id)->where('key', $event)->first();
        [$subject,$body] = self::DEFAULTS[$event];
        $variables = ['{name}' => $member->promoter->display_name, '{program}' => $member->program->name];
        if ($event === 'customer.invited') {
            $url = (string) ($context['invitation_url'] ?? '');
            abort_unless(str_starts_with($url, url('/referral-portal/invitation').'#token=') && preg_match('/[a-f0-9]{64}$/D', $url), 422);
            $variables['{invitation_url}'] = $url;
        }
        $intent = AffiliateNotificationIntent::query()->firstOrCreate(['store_id' => $member->store_id, 'dedupe_key' => $dedupe], [
            'organization_id' => $member->organization_id, 'membership_id' => $member->id, 'event_key' => $event, 'template_version' => $template->version ?? 1,
            'recipient_hash' => $member->promoter->email_hash, 'status' => ($template->enabled ?? false) ? 'queued' : 'suppressed',
            'message_encrypted' => ['to' => $member->promoter->email_encrypted, 'subject' => strtr($template->subject ?? $subject, $variables), 'body' => strtr($template->body ?? $body, $variables)]]);
        if ($intent->wasRecentlyCreated && $intent->status === 'queued') {
            SendAffiliateNotification::dispatch($intent->id)->afterCommit();
        }

        return $intent;
    }

    public function send(int $id): void
    {
        $record = AffiliateNotificationIntent::query()->findOrFail($id);
        $store = Store::query()->where('organization_id', $record->organization_id)->findOrFail($record->store_id);
        app(AffiliateShopGuard::class)->store($store);
        $lock = Cache::lock('affiliate-mail:'.$id, 120);
        $lock->block(5, function () use ($record, $store) {
            $record->refresh();
            if ($record->status === 'sent' || $record->status === 'suppressed') {
                return;
            }
            if ($record->event_key === 'portal.login' && $record->created_at->addMinutes(15)->lte(now())) {
                $record->update(['status' => 'suppressed', 'error_summary' => '登录链接已过期，请重新申请。', 'message_encrypted' => []]);

                return;
            }
            if ($record->event_key === 'customer.invited') {
                $member = AffiliateProgramMembership::query()->forOrganization($store->organization_id)->forStore($store)->find($record->membership_id);
                $orderId = data_get($member?->application_encrypted, 'source_order_id');
                $contact = $record->created_at->copy()->addDays(7)->isFuture() && $orderId ? app(AffiliateInvitationOrderReader::class)->eligibleContact($store, $orderId) : null;
                if (! $contact || $contact['email'] !== data_get($record->message_encrypted, 'to')) {
                    $record->update(['status' => 'suppressed', 'message_encrypted' => [], 'error_summary' => '邀请已过期或顾客不再符合接收条件。']);

                    return;
                }
            }
            if (config('mail.default') === 'log') {
                throw new \RuntimeException('Configure a non-logging mail transport');
            }
            $record->increment('attempts');
            $message = $record->message_encrypted;
            try {
                $sent = Mail::raw($message['body'], fn ($mail) => $mail->to($message['to'])->subject($message['subject']));
                $record->update(['status' => 'sent', 'sent_at' => now(), 'provider_id' => $sent?->getMessageId(), 'error_summary' => null]);
            } catch (\Throwable $e) {
                $record->update(['status' => 'failed', 'error_summary' => '邮件发送失败，请检查发送服务。']);
                throw new \RuntimeException('Affiliate mail delivery failed');
            }
        });
    }
}
