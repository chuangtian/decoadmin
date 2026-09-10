<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><meta charset="utf-8"></head><body style="margin:0;padding:0;background:#f4f6f8;color:#17212b;font-family:Arial,sans-serif">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8"><tr><td align="center" style="padding:24px 12px">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px"><tr><td style="padding:32px">
<h2 style="margin:0 0 24px;font-size:24px">{{ $store->name }}</h2>
<div style="font-size:16px;line-height:1.7">{!! nl2br(e($body)) !!}</div>
@if($coupon !== '')<p style="padding:16px;background:#f0fdf4;border:1px dashed #059669;text-align:center;font-size:20px;font-weight:bold">{{ $coupon }}</p>@endif
@if(count($products))<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:24px">@foreach($products as $product)<tr>
@if($product['image'])<td width="100" style="padding:12px 12px 12px 0"><img src="{{ $product['image'] }}" alt="{{ $product['title'] }}" width="88" style="display:block;max-width:88px;height:auto"></td>@endif
<td style="padding:12px 0;border-bottom:1px solid #e2e8f0"><b>{{ $product['title'] }}</b><br><span style="font-size:14px;color:#64748b">{{ $product['quantity'] }} × {{ $product['price'] }} {{ $product['currency'] }}</span></td></tr>@endforeach</table>@endif
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0"><tr><td bgcolor="#047857" style="border-radius:8px"><a href="{{ $click }}" style="display:inline-block;padding:15px 24px;color:#ffffff;font-size:16px;text-decoration:none;font-weight:bold">{{ $button }}</a></td></tr></table>
<p style="border-top:1px solid #e2e8f0;padding-top:20px;font-size:12px;line-height:1.6;color:#64748b">You received this email from {{ $store->name }}. <a href="{{ $unsubscribe }}" style="color:#047857">Unsubscribe</a></p>
</td></tr></table></td></tr></table>@if($pixel)<img src="{{ $pixel }}" width="1" height="1" alt="">@endif</body></html>
