<?php
// Retained fixtures only. This script refuses live transport and any other shop/environment.
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Store;
use DecoMarketing\Models\{Settings,Flow,Contact,Enrollment,Delivery,Campaign};
use DecoMarketing\Services\{Guard,Catalog,Contacts,Engine};
if (!app()->environment(['local','staging','testing','test']) || config('marketing.transport')!=='preview') throw new RuntimeException('Preview test environment required.');
$store=Store::where('shopify_domain',Guard::SHOP)->sole(); app(Guard::class)->store($store);
app(Catalog::class)->setup($store);
$settings=Settings::forStore($store)->firstOrFail(); $settings->update(['enabled'=>true]);
Flow::forStore($store)->where('key','welcome')->firstOrFail()->update(['enabled'=>true,'steps'=>[['template'=>'welcome_1','after_minutes'=>0],['template'=>'welcome_2','after_minutes'=>60]]]);
$contacts=app(Contacts::class);
$ok=$contacts->upsert($store,['email'=>'marketing-preview-20260909@example.invalid','name'=>'营销验收 · 正常订阅','consent'=>'subscribed','consent_at'=>now()->toIso8601String()],'test');
$optout=$contacts->upsert($store,['email'=>'marketing-optout-20260909@example.invalid','name'=>'营销验收 · 退订拦截','consent'=>'subscribed','consent_at'=>now()->toIso8601String()],'test');
$blocked=$contacts->upsert($store,['email'=>'marketing-blocked-20260909@example.invalid','name'=>'营销验收 · 禁止发送','consent'=>'subscribed','consent_at'=>now()->toIso8601String()],'test');
app(Engine::class)->enroll($store,$ok,'welcome','pilot-preview-20260909');
app(Engine::class)->enroll($store,$optout,'welcome','pilot-optout-20260909'); $contacts->unsubscribe($optout);
$contacts->suppress($blocked);
app(Engine::class)->enroll($store,$blocked,'welcome','pilot-blocked-20260909');
Campaign::firstOrCreate(['organization_id'=>$store->organization_id,'store_id'=>$store->id,'name'=>'营销验收 · 群发草稿（未发送）'],['audience'=>'subscribed','content'=>['subject'=>'Test campaign — macfox-test-app only','body'=>'Retained test draft. No production customers.','button'=>'Visit test store']]);
app(Engine::class)->run($store); app(Engine::class)->run($store);
$result=['shop'=>$store->shopify_domain,'management_url'=>route('marketing.index',[$store->organization_id,$store->id]),'contacts'=>Contact::forStore($store)->count(),'simulated'=>Delivery::forStore($store)->where('status','simulated')->count(),'actual_sent'=>Delivery::forStore($store)->whereNotNull('sent_at')->count(),'stopped'=>Enrollment::forStore($store)->where('status','stopped')->count()];
echo json_encode($result,JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
