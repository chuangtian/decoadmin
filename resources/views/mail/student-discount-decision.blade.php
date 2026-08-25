<!doctype html>
<html lang="zh-CN">
<body style="font-family:Arial,sans-serif;color:#0f172a;line-height:1.6">
    @if ($discountCode)
        <h1>学生优惠审核已通过</h1>
        <p>你的优惠码是：</p>
        <p style="font-size:24px;font-weight:bold;letter-spacing:1px">{{ $discountCode->code }}</p>
        <p>有效期至 {{ $discountCode->expires_at->toIso8601String() }}，最多可使用 {{ $discountCode->usage_limit }} 次。</p>
    @else
        <h1>学生优惠申请未通过</h1>
        <p>原因：{{ $claim->rejection_reason }}</p>
        <p>你可以重新提交一份新的申请。</p>
    @endif
</body>
</html>
