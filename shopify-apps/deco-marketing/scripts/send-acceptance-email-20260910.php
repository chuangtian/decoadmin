<?php
// User explicitly authorized this single real test email. No global transport settings change.
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Store;
use DecoMarketing\Models\{Contact,Delivery};
use DecoMarketing\Services\{Guard,Contacts,Renderer,Transport};
use Illuminate\Support\Facades\Cache;
if(!app()->environment('staging')||config('marketing.transport')!=='preview')throw new RuntimeException('Staging preview required.');
$s=Store::where('shopify_domain',Guard::SHOP)->sole();app(Guard::class)->store($s);
app(App\Services\SystemSettingsService::class)->applyRuntimeConfiguration();
config(['marketing.transport'=>'system']);
$key=hash('sha256','deco-marketing-real-mail-20260910-v1');
Cache::lock('marketing:test:'.$key,120)->block(1,function()use($s,$key){
 $existing=Delivery::where('dedupe_key',$key)->first();if($existing && ($existing->attempts > 0 || $existing->first_attempt_at)){echo json_encode(['existing'=>true,'status'=>$existing->status,'uuid'=>$existing->uuid]).PHP_EOL;return;}
 $c=Contact::forStore($s)->where('email_hash',Contacts::hash('jiushizheyike@gmail.com'))->first()??app(Contacts::class)->upsert($s,['email'=>'jiushizheyike@gmail.com','name'=>'Deco 测试收件人','consent'=>'not_subscribed'],'test');
 $d=$existing ?? Delivery::create(['organization_id'=>$s->organization_id,'store_id'=>$s->id,'contact_id'=>$c->id,'kind'=>'template_test','dedupe_key'=>$key]);
 $content=['subject'=>'[Deco TEST 20260910] 营销邮件效果验收 · macfox-test-app','body'=>"你好，这是一封你授权发送的 Deco Marketing 测试邮件。\n\n本邮件仅验证 macfox-test-app 的邮件排版、打开记录、测试店跳转与退订入口，不代表购买、付款或真实优惠活动。\n\nThis is an authorized test message for macfox-test-app. No purchase or payment is required.\n\n点击下方按钮可访问测试店铺。底部提供退订入口。",'button'=>'访问 macfox-test-app 测试店'];
 $d->update(['payload_encrypted'=>app(Renderer::class)->render($s,$c,$content,[],$d),'status'=>'sending','attempts'=>1,'first_attempt_at'=>now()]);
 $r=app(Transport::class)->send($s,$d);$d->update(['status'=>$r['status'],'reason'=>$r['reason'],'provider_id'=>$r['id'],'sent_at'=>$r['status']==='sent'?now():null]);
 echo json_encode(['uuid'=>$d->uuid,'status'=>$d->status,'reason'=>$d->reason,'recipient'=>'jiushizheyike@gmail.com']).PHP_EOL;
});
