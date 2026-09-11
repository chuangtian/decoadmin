<?php

namespace DecoMarketing\Services;

use App\Models\Store;
use DecoMarketing\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

class Audiences
{
    public const LABELS = ['subscribed' => '全部订阅者', 'non_buyers' => '订阅未购', 'buyers' => '已购订阅者', 'advocacy_opened_not_clicked' => '老带新已打开未点击'];

    public function query(Store $store, string $audience): Builder
    {
        app(Guard::class)->store($store);
        abort_unless(array_key_exists($audience, self::LABELS), 422);
        $query = Contact::forStore($store)->where('consent', 'subscribed')->where('suppressed', false);
        if ($audience === 'non_buyers') {
            $query->where('orders_count', 0);
        }
        if ($audience === 'buyers') {
            $query->where('orders_count', '>', 0);
        }
        if ($audience === 'advocacy_opened_not_clicked') {
            $base = fn ($q) => $q->where('kind', 'automation')->whereNotNull('sent_at')->whereHas('enrollment', fn ($e) => $e->where('flow_key', 'advocacy'));
            $query->whereHas('deliveries', fn ($q) => $base($q)->whereNotNull('opened_at'))
                ->whereDoesntHave('deliveries', fn ($q) => $base($q)->whereNotNull('clicked_at'));
        }

        return $query;
    }
}
