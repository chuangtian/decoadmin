<?php
namespace DecoMarketing\Services;

use App\Domain\ReferralAffiliate\Models\AffiliateProgramMembership;
use App\Models\Store;
use DecoMarketing\Models\Contact;
use Illuminate\Validation\ValidationException;

class ReferralContext
{
    /** Resolve only an exact identity in this organization/store; ambiguity never selects a random code. */
    public function code(Store $store, Contact $contact): string
    {
        app(Guard::class)->store($store);
        abort_unless($contact->store_id === $store->id && $contact->organization_id === $store->organization_id,403);
        $hash=hash_hmac('sha256',$store->organization_id.'|'.mb_strtolower(trim($contact->email_encrypted)),(string)config('app.key'));
        $members=AffiliateProgramMembership::query()->where('organization_id',$store->organization_id)->where('store_id',$store->id)->where('status','approved')
            ->whereHas('promoter',fn($q)=>$q->where('organization_id',$store->organization_id)->where('status','active')->where('email_hash',$hash))
            ->whereHas('program',fn($q)=>$q->where('organization_id',$store->organization_id)->where('store_id',$store->id)->where('status','active')->where('coupon_enabled',true)->where('currency','USD')->where('customer_discount_type','fixed')->where(fn($q)=>$q->whereNull('starts_at')->orWhere('starts_at','<=',now()))->where(fn($q)=>$q->whereNull('ends_at')->orWhere('ends_at','>',now())))
            ->whereHas('coupon',fn($q)=>$q->where('organization_id',$store->organization_id)->where('store_id',$store->id)->where('status','active')->whereNotNull('shopify_discount_id')
                ->where(fn($q)=>$q->whereNull('starts_at')->orWhere('starts_at','<=',now()))->where(fn($q)=>$q->whereNull('ends_at')->orWhere('ends_at','>',now())))
            ->with('coupon','program')->limit(2)->get();
        if($members->count()!==1)throw ValidationException::withMessages(['content'=>'未找到唯一有效的推荐计划和推荐码，请先核对推荐与联盟配置。']);
        // The captured legacy mail promises $150 off. Never substitute a different offer.
        if($store->currency!=='USD'||(int)$members->first()->program->customer_discount_amount_minor!==15000)throw ValidationException::withMessages(['content'=>'推荐计划优惠金额与邮件中的150美元不一致。']);
        $code=$members->first()->coupon->code;
        if((app(Coupons::class)->check($store,$code)['status']??null)!=='ACTIVE')throw ValidationException::withMessages(['content'=>'推荐码未通过Shopify实时有效性检查。']);
        return $code;
    }
}
