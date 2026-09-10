<?php

namespace DecoMarketing\Services;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use DecoMarketing\Models\Campaign;
use DecoMarketing\Models\Contact;
use DecoMarketing\Models\Delivery;
use DecoMarketing\Models\Enrollment;
use DecoMarketing\Models\Flow;
use DecoMarketing\Models\Template;
use DecoMarketing\Models\Waitlist;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Management
{
    public function change(Store $store, User $actor, string $action, array $input): string
    {
        app(Guard::class)->actor($store, $actor, true);
        $message = match ($action) {
            'initialize' => $this->initialize($store),
            'coupons' => $this->coupons($store, $input),
            'settings' => $this->settings($store, $input),
            'flow' => $this->flow($store, $input),
            'contact' => $this->contact($store, $input),
            'import' => $this->import($store, $input),
            'template' => $this->template($store, $input),
            'template-test' => $this->test($store, $input),
            'template-publish' => $this->publish($store, $input),
            'template-toggle' => $this->toggleTemplate($store, $input),
            'template-reset' => $this->resetTemplate($store, $input),
            'campaign-test' => $this->test($store, $input, true),
            'campaign' => $this->campaign($store, $input),
            'campaign-transition' => $this->transition($store, $input),
            'waitlist' => $this->waitlist($store, $input),
            'run' => $this->run($store),
            'sync' => $this->sync($store),
            default => throw ValidationException::withMessages(['action' => '不支持此操作。']),
        };
        AuditLog::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'user_id' => $actor->id, 'action' => 'marketing.'.$action, 'metadata' => ['scope' => 'store']]);

        return $message;
    }

    private function initialize(Store $store): string
    {
        app(Catalog::class)->setup($store);

        return '测试工作区已初始化，所有流程默认关闭。';
    }

    private function coupons(Store $store, array $input): string
    {
        $v = validator($input, ['codes' => 'present|array|max:10', 'codes.*' => 'required|string|max:100|regex:/^[A-Za-z0-9_-]+$/'])->validate();
        app(Catalog::class)->settings($store)->update(['coupons' => array_values(array_unique($v['codes']))]);

        return '巡检优惠码已保存。';
    }

    private function settings(Store $store, array $input): string
    {
        $v = validator($input, ['welcome_coupon' => 'sometimes|nullable|string|max:100|regex:/^[A-Za-z0-9_-]+$/', 'enabled' => 'required|boolean', 'daily_limit' => 'required|integer|min:1|max:5000', 'frequency_hours' => 'required|integer|min:0|max:168', 'timezone' => 'required|timezone',
            'warmup_enabled' => 'sometimes|boolean', 'warmup_steps' => 'sometimes|array|min:2|max:20', 'warmup_steps.*' => 'integer|min:1|max:5000', 'popup' => 'required|array', 'popup.enabled' => 'required|boolean', 'popup.heading' => 'required|string|max:100', 'popup.body' => 'required|string|max:500',
        ])->validate();
        if (isset($v['warmup_steps'])) {
            $sorted = $v['warmup_steps'];
            sort($sorted, SORT_NUMERIC);
            if ($sorted !== $v['warmup_steps']) {
                throw ValidationException::withMessages(['warmup_steps' => '预热阶梯必须递增。']);
            }
        }
        if ($v['enabled'] && config('marketing.transport') === 'resend' && (! config('marketing.resend_key') || ! config('marketing.from'))) {
            throw ValidationException::withMessages(['enabled' => '发信通道未配置，不能启用真实发送。']);
        }
        app(Catalog::class)->settings($store)->update($v);

        return '设置已保存。';
    }

    private function flow(Store $store, array $input): string
    {
        $v = validator($input, ['uuid' => 'required|uuid', 'enabled' => 'required|boolean', 'steps' => 'required|array|min:1|max:10', 'steps.*.template' => ['required', Rule::in(array_keys(Catalog::TEMPLATES))], 'steps.*.after_minutes' => 'required|integer|min:0|max:525600'])->validate();
        $last = -1;
        foreach ($v['steps'] as $step) {
            if ($step['after_minutes'] <= $last) {
                throw ValidationException::withMessages(['steps' => '步骤时间必须递增，时间从进入流程时计算。']);
            } $last = $step['after_minutes'];
        }
        $flow = Flow::forStore($store)->where('uuid', $v['uuid'])->firstOrFail();
        $flow->update(['enabled' => $v['enabled'], 'steps' => $v['steps'], 'version' => $flow->version + 1]);

        return '流程已保存；已有任务保留原步骤，启停立即生效。';
    }

    private function contact(Store $store, array $input): string
    {
        $v = validator($input, ['uuid' => 'required|uuid', 'action' => 'required|in:suppress,unsuppress,unsubscribe,timezone', 'timezone' => 'required_if:action,timezone|nullable|timezone'])->validate();
        $contact = Contact::forStore($store)->where('uuid', $v['uuid'])->firstOrFail();
        if ($v['action'] === 'timezone') {
            $contact->update(['timezone' => $v['timezone']]);
            return '联系人发送时区已保存。';
        }
        if ($v['action'] === 'suppress') {
            app(Contacts::class)->suppress($contact);
        } elseif ($v['action'] === 'unsubscribe') {
            app(Contacts::class)->unsubscribe($contact);
        } else {
            $contact->update(['suppressed' => false, 'suppression_reason' => null]);
        }

        return '联系人状态已更新。解除禁止发送不会恢复订阅或重启旧任务。';
    }

    public function importPreview(Store $store, User $actor, array $input): array
    {
        app(Guard::class)->actor($store, $actor, true);
        $v = validator($input, ['csv' => 'required|string|max:200000'])->validate();
        $result = app(Contacts::class)->parseImport($v['csv']);

        return ['total' => $result['total'], 'errors' => $result['errors'], 'digest' => hash('sha256', $v['csv'])];
    }

    private function import(Store $store, array $input): string
    {
        $v = validator($input, ['csv' => 'required|string|max:200000', 'digest' => 'required|string|size:64'])->validate();
        if (! hash_equals(hash('sha256', $v['csv']), $v['digest'])) {
            throw ValidationException::withMessages(['csv' => '数据已变更，请重新预检。']);
        }
        $result = app(Contacts::class)->parseImport($v['csv']);
        if ($result['errors']) {
            throw ValidationException::withMessages(['csv' => implode('；', array_slice($result['errors'], 0, 5))]);
        }
        DB::transaction(function () use ($result, $store) {
            foreach ($result['rows'] as $row) {
                app(Contacts::class)->upsert($store, $row, 'import');
            }
        });

        return '已处理 '.$result['total'].' 条联系人；重复邮箱合并，未触发历史欢迎邮件。';
    }

    private function template(Store $store, array $input): string
    {
        $v = validator($input, ['uuid' => 'required|uuid', 'content' => 'required|array'])->validate();
        $content = app(Renderer::class)->validate($v['content']);
        Template::forStore($store)->where('uuid', $v['uuid'])->firstOrFail()->update(['draft' => $content, 'tested_hash' => null, 'tested_at' => null]);

        return '草稿已保存，需要发送测试后才能发布。';
    }

    private function test(Store $store, array $input, bool $campaignTest = false): string
    {
        $v = validator($input, ['uuid' => 'required|uuid', 'recipient' => 'required|email|max:254', 'request_id' => 'required|uuid', 'variant' => 'nullable|in:A,B'])->validate();
        if (! app(Guard::class)->recipient($v['recipient'])) {
            throw ValidationException::withMessages(['recipient' => '测试邮件只能发送给已授权的测试邮箱。']);
        }
        $template = $campaignTest ? Campaign::forStore($store)->where('uuid', $v['uuid'])->firstOrFail() : Template::forStore($store)->where('uuid', $v['uuid'])->firstOrFail();
        $content = $campaignTest ? (($input['variant'] ?? 'A') === 'B' ? $template->variant_b : $template->content) : $template->draft;
        if (! $content) {
            throw ValidationException::withMessages(['variant' => '所选版本不存在。']);
        }
        $content = app(Renderer::class)->validate($content);
        $key = hash('sha256', ($campaignTest ? 'campaign-test|' : 'template|').$store->id.'|'.$template->uuid.'|'.$v['request_id']);
        $lock = Cache::lock('marketing:test:'.$key, 60);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['request_id' => '这次测试正在处理。']);
        }
        try {
            $existing = Delivery::where('dedupe_key', $key)->first();
            if ($existing) {
                return '这次测试已有记录，请查看发送日志；不会重复发送。';
            }
            $contact = Contact::forStore($store)->where('email_hash', Contacts::hash($v['recipient']))->first()
                ?? app(Contacts::class)->upsert($store, ['email' => $v['recipient'], 'name' => 'Test recipient', 'consent' => 'not_subscribed'], 'test');
            $delivery = Delivery::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'contact_id' => $contact->id, 'kind' => $campaignTest ? 'campaign_test' : 'template_test', 'template_key' => $campaignTest ? null : $template->key, 'dedupe_key' => $key]);
            $delivery->update(['payload_encrypted' => app(Renderer::class)->render($store, $contact, $content, ['order_name' => 'TEST-ORDER', 'product_title' => 'Test product', 'coupon_code' => 'TEST-COUPON', 'referral_code' => 'TEST-REFERRAL'], $delivery),
                'status' => 'sending', 'first_attempt_at' => now(), 'attempts' => 1, 'lease_until' => now()->addMinute()]);
            $result = app(Transport::class)->send($store, $delivery);
            $delivery->update(['status' => $result['status'], 'reason' => $result['reason'], 'provider_id' => $result['id'], 'lease_until' => null, 'sent_at' => $result['status'] === 'sent' ? now() : null]);
            if ($result['status'] === 'sent') {
                if (! $campaignTest) {
                    $template->update(['tested_hash' => hash('sha256', json_encode($content)), 'tested_at' => now()]);
                }

                return '测试邮件已提交发信服务，请核对收件箱。';
            }
            if ($result['status'] === 'simulated') {
                return '预演完成，内容已记录；没有发送真实邮件，尚不能发布草稿。';
            }

            return '测试未确认成功，状态已保留在发送日志；没有自动重复发送。';
        } finally {
            $lock->release();
        }
    }

    private function toggleTemplate(Store $store, array $input): string
    {
        $v = validator($input, ['uuid' => 'required|uuid', 'enabled' => 'required|boolean'])->validate();
        Template::forStore($store)->where('uuid', $v['uuid'])->firstOrFail()->update(['enabled' => $v['enabled']]);

        return $v['enabled'] ? '模板已启用；已跳过的旧步骤不会补发。' : '模板已停用；排队中的对应邮件将跳过。';
    }

    private function resetTemplate(Store $store, array $input): string
    {
        $v = validator($input, ['uuid' => 'required|uuid'])->validate();
        $template = Template::forStore($store)->where('uuid', $v['uuid'])->firstOrFail();
        $content = app(Catalog::class)->defaultContent($template->key);
        if (! $content) {
            throw ValidationException::withMessages(['template' => '此模板的旧版默认正文尚未核对，暂不能恢复。']);
        }
        $template->update(['draft' => $content, 'published' => $content, 'version' => $template->version + 1, 'tested_hash' => null, 'tested_at' => null]);

        return '已恢复默认版本；已有任务保留原邮件内容。';
    }

    private function publish(Store $store, array $input): string
    {
        $v = validator($input, ['uuid' => 'required|uuid'])->validate();
        DB::transaction(function () use ($store, $v) {
            $template = Template::forStore($store)->where('uuid', $v['uuid'])->lockForUpdate()->firstOrFail();
            if (! $template->tested_hash || ! hash_equals($template->tested_hash, hash('sha256', json_encode($template->draft)))) {
                throw ValidationException::withMessages(['template' => '当前草稿尚未成功发送测试。']);
            }
            $template->update(['published' => $template->draft, 'version' => $template->version + 1]);
        });

        return '模板已发布，新任务使用此版本。';
    }

    private function campaign(Store $store, array $input): string
    {
        $v = validator($input, ['smart_timing' => 'sometimes|boolean', 'name' => 'required|string|max:150', 'audience' => ['required', Rule::in(array_keys(Audiences::LABELS))], 'content' => 'required|array', 'variant_b' => 'nullable|array', 'test_percent' => 'required|integer|min:10|max:50'])->validate();
        $v['content'] = app(Renderer::class)->validate($v['content']);
        if (! empty($v['variant_b'])) {
            $v['variant_b'] = app(Renderer::class)->validate($v['variant_b']);
        }
        Campaign::create(['organization_id' => $store->organization_id, 'store_id' => $store->id, ...$v]);

        return '群发草稿已创建，尚未开始发送。';
    }

    private function transition(Store $store, array $input): string
    {
        $v = validator($input, ['uuid' => 'required|uuid', 'action' => 'required|in:start,pause,cancel', 'scheduled_at' => 'nullable|date'])->validate();
        DB::transaction(function () use ($store, $v) {
            $campaign = Campaign::forStore($store)->where('uuid', $v['uuid'])->lockForUpdate()->firstOrFail();
            if (in_array($campaign->status, ['completed', 'cancelled'])) {
                throw ValidationException::withMessages(['campaign' => '已结束的活动不能重新启动。']);
            }
            if ($v['action'] === 'start') {
                if (! app(Catalog::class)->settings($store)->enabled) {
                    throw ValidationException::withMessages(['campaign' => '请先启用工作区。']);
                }
                if ($campaign->stop_reason === 'unsubscribe_limit') {
                    throw ValidationException::withMessages(['campaign' => '该活动因退订率超限暂停，请调整内容后创建新活动。']);
                }
                $at = ! empty($v['scheduled_at']) ? CarbonImmutable::parse($v['scheduled_at']) : now();
                $campaign->update(['status' => 'sending', 'scheduled_at' => $at, 'started_at' => $campaign->started_at ?? now()]);
            } elseif ($v['action'] === 'pause') {
                $campaign->update(['status' => 'paused']);
            } else {
                $campaign->update(['status' => 'cancelled']);
                Enrollment::forStore($store)->where('campaign_id', $campaign->id)->whereIn('status', ['active', 'waiting'])->update(['status' => 'stopped', 'stop_reason' => 'campaign_cancelled', 'next_at' => null]);
            }
        });

        return '群发状态已更新。';
    }

    private function waitlist(Store $store, array $input): string
    {
        $v = validator($input, ['lines' => 'required|string|max:100000'])->validate();
        $lines = preg_split('/\r\n|\n|\r/', trim($v['lines']));
        if (count($lines) > 200) {
            throw ValidationException::withMessages(['lines' => '每批最多 200 行。']);
        }
        DB::transaction(function () use ($lines, $store) {
            foreach ($lines as $i => $line) {
                $row = str_getcsv($line, ',', '"', '');
                if (count($row) < 2 || ! filter_var(trim($row[0]), FILTER_VALIDATE_EMAIL) || ! trim($row[1]) || strlen(trim($row[1])) > 120) {
                    throw ValidationException::withMessages(['lines' => '第 '.($i + 1).' 行格式无效。']);
                }
                $contact = Contact::forStore($store)->where('email_hash', Contacts::hash($row[0]))->first();
                if (! $contact || $contact->consent !== 'subscribed' || $contact->suppressed) {
                    throw ValidationException::withMessages(['lines' => '第 '.($i + 1).' 行联系人尚未订阅或已禁止发送，请先取得订阅许可。']);
                }
                Waitlist::firstOrCreate(['organization_id' => $store->organization_id, 'store_id' => $store->id, 'contact_id' => $contact->id, 'sku' => trim($row[1])], ['product_title' => mb_substr(trim($row[2] ?? ''), 0, 200)]);
            }
        });

        return '到货名单已导入，重复记录已合并。';
    }

    private function run(Store $store): string
    {
        $result = app(Engine::class)->run($store);

        return '本轮处理 '.$result['processed'].' 条任务。'.($result['reason'] ? '（'.$result['reason'].'）' : '');
    }

    private function sync(Store $store): string
    {
        $result = app(Shopify::class)->sync($store);

        return '本批同步完成（每类最多 100 条，再次同步会接续进度）：'.$result['contacts'].' 位客户、'.$result['orders'].' 笔订单、'.$result['checkouts'].' 条弃购。';
    }
}
