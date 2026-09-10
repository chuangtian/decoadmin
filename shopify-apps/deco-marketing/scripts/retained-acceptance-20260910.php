<?php
// Explicitly synthetic evidence in the authorized test store; never deletes records or sends email.
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Store;
use DecoMarketing\Models\{Campaign,Contact,Delivery,Enrollment,OrderSnapshot};
use DecoMarketing\Services\{Guard,Contacts,Campaigns,ConversionReports};
if(!app()->environment('staging')||config('marketing.transport')!=='preview')throw new RuntimeException('Staging preview only.');
$s=Store::where('shopify_domain',Guard::SHOP)->sole();app(Guard::class)->store($s);
$campaign=Campaign::firstOrCreate(['store_id'=>$s->id,'organization_id'=>$s->organization_id,'name'=>'[模拟验收] 20260910 A/B 打开优先'],['content'=>['subject'=>'模拟 A：高点击','body'=>'合成数据，不发送。','button'=>'Test'],'variant_b'=>['subject'=>'模拟 B：高打开','body'=>'合成数据，不发送。','button'=>'Test'],'status'=>'sending','scheduled_at'=>now()->subDays(2),'expanded_at'=>now()->subDays(2),'test_ends_at'=>now()->subMinute()]);
foreach(['A','B','hold'] as $variant){
 $c=app(Contacts::class)->upsert($s,['email'=>'qa-ab-'.strtolower($variant).'-20260910@example.invalid','name'=>'[模拟验收] '.$variant,'consent'=>'not_subscribed'],'test_fixture');
 $e=Enrollment::firstOrCreate(['organization_id'=>$s->organization_id,'store_id'=>$s->id,'contact_id'=>$c->id,'flow_key'=>'campaign','source_key'=>$campaign->uuid],['campaign_id'=>$campaign->id,'variant'=>$variant,'status'=>$variant==='hold'?'waiting':'completed','steps'=>[],'context_encrypted'=>['synthetic'=>true,'mode'=>'preview']]);
 if($variant!=='hold')Delivery::firstOrCreate(['dedupe_key'=>hash('sha256','qa-ab-'.$variant.'-20260910')],['organization_id'=>$s->organization_id,'store_id'=>$s->id,'contact_id'=>$c->id,'enrollment_id'=>$e->id,'kind'=>'automation','status'=>'sent','sent_at'=>now()->subDay(),'clicked_at'=>$variant==='A'?now():null,'opened_at'=>now(),'human_opened_at'=>$variant==='B'?now():null,'open_classifier'=>'synthetic-fixture','payload_encrypted'=>['subject'=>'[模拟验收] 非实际邮件 '.$variant,'synthetic'=>true]]);
}
// Resume after an interrupted schema rollout: only these explicitly synthetic events may be repaired.
foreach (['A','B'] as $variant) {
 $d=Delivery::forStore($s)->where('dedupe_key',hash('sha256','qa-ab-'.$variant.'-20260910'))->firstOrFail();
 if (($d->payload_encrypted['synthetic']??false)!==true) throw new RuntimeException('Synthetic record required.');
 if (!$d->open_classifier) $d->update(['open_classifier'=>'synthetic-fixture','human_opened_at'=>$variant==='B'?now():null]);
}

app(Campaigns::class)->expand($s);$campaign->refresh();if($campaign->winner!=='B')throw new RuntimeException('A/B verification failed.');
$campaign->update(['status'=>'paused','stop_reason'=>'synthetic_acceptance_complete']);
foreach(['received','not_received'] as $group){
 $c=app(Contacts::class)->upsert($s,['email'=>'qa-cohort-'.$group.'-20260910@example.invalid','name'=>'[模拟验收] 14日转化 '.$group,'consent'=>'not_subscribed'],'test_fixture');
 if(!$c->first_subscribed_at)$c->update(['first_subscribed_at'=>now()->subDays(20)]);
 OrderSnapshot::firstOrCreate(['store_id'=>$s->id,'organization_id'=>$s->organization_id,'order_id'=>'QA-COHORT-'.$group.'-20260910'],['contact_id'=>$c->id,'name'=>'[模拟订单] '.$group,'financial_status'=>'PAID','currency'=>'USD','total'=>100,'outstanding'=>0,'ordered_at'=>$c->first_subscribed_at->copy()->addDays(2)]);
 if($group==='received'){
  $e=Enrollment::firstOrCreate(['organization_id'=>$s->organization_id,'store_id'=>$s->id,'contact_id'=>$c->id,'flow_key'=>'welcome','source_key'=>'QA-COHORT-20260910'],['status'=>'completed','steps'=>[]]);
  Delivery::firstOrCreate(['dedupe_key'=>hash('sha256','qa-cohort-welcome-20260910')],['organization_id'=>$s->organization_id,'store_id'=>$s->id,'contact_id'=>$c->id,'enrollment_id'=>$e->id,'kind'=>'automation','status'=>'sent','sent_at'=>$c->first_subscribed_at->copy()->addMinute(),'payload_encrypted'=>['subject'=>'[模拟验收] 欢迎信统计','synthetic'=>true]]);
 }
}
$r=app(ConversionReports::class)->report($s);
echo json_encode(['synthetic'=>true,'shop'=>$s->shopify_domain,'ab_winner'=>$campaign->winner,'cohort'=>$r['cohort'],'mail_sent_by_script'=>0],JSON_UNESCAPED_UNICODE).PHP_EOL;
