@php
    $branding = $renderedTemplate['branding'] ?? [];
    $primaryColor = $branding['primary_color'] ?? '#111111';
    $storeName = $renderedTemplate['store_name'] ?? config('app.name');
    $isApproval = ($renderedTemplate['type'] ?? '') === 'approval';
    $supportHref = filled($branding['support_url'] ?? null)
        ? $branding['support_url']
        : (filled($branding['support_email'] ?? null) ? 'mailto:'.$branding['support_email'] : null);
    $socials = collect([
        'Instagram' => $branding['instagram_url'] ?? '',
        'Facebook' => $branding['facebook_url'] ?? '',
        'TikTok' => $branding['tiktok_url'] ?? '',
        'YouTube' => $branding['youtube_url'] ?? '',
    ])->filter();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $renderedTemplate['subject'] ?? 'Student discount update' }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#171717;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $renderedTemplate['preheader'] ?? '' }}</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f3f4f6;">
        <tr><td align="center" style="padding:28px 12px;">
            <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#ffffff;border-collapse:collapse;">
                @if ($isTest)
                    <tr><td style="padding:10px 24px;background:#eff6ff;color:#1d4ed8;text-align:center;font-size:12px;font-weight:700;">TEST EMAIL · This message was sent from the DecoAdmin preview.</td></tr>
                @endif
                <tr><td align="center" style="padding:30px 36px 26px;border-bottom:1px solid #e5e7eb;">
                    @if (filled($branding['logo_url'] ?? null))
                        <img src="{{ $branding['logo_url'] }}" alt="{{ $storeName }}" width="220" style="display:block;max-width:220px;max-height:72px;width:auto;height:auto;border:0;">
                    @else
                        <div style="font-size:24px;line-height:32px;font-weight:800;color:{{ $primaryColor }};">{{ $storeName }}</div>
                    @endif
                </td></tr>
                <tr><td style="padding:34px 36px 10px;">
                    <h1 style="margin:0 0 20px;font-size:32px;line-height:40px;font-weight:800;color:#111111;">{{ $renderedTemplate['heading'] ?? '' }}</h1>
                    <div style="font-size:17px;line-height:28px;color:#404040;">{!! nl2br(e($renderedTemplate['body'] ?? '')) !!}</div>
                </td></tr>
                @if ($isApproval && filled($renderedTemplate['discount_code'] ?? null))
                    <tr><td style="padding:20px 36px 8px;"><div style="border:3px solid {{ $primaryColor }};padding:26px 20px;text-align:center;font-family:'Courier New',monospace;font-size:27px;line-height:34px;font-weight:800;letter-spacing:2px;color:#111111;word-break:break-all;">{{ $renderedTemplate['discount_code'] }}</div></td></tr>
                @endif
                @if (filled($branding['shop_url'] ?? null) && filled($renderedTemplate['cta_label'] ?? null))
                    <tr><td style="padding:14px 36px 8px;"><a href="{{ $branding['shop_url'] }}" style="display:block;padding:18px 24px;background:{{ $primaryColor }};color:#ffffff;text-align:center;text-decoration:none;font-size:18px;line-height:24px;font-weight:800;">{{ $renderedTemplate['cta_label'] }}</a></td></tr>
                @endif
                @if ($isApproval && (filled($renderedTemplate['expires_at'] ?? null) || filled($renderedTemplate['usage_limit'] ?? null)))
                    <tr><td style="padding:12px 36px 0;font-size:13px;line-height:21px;color:#737373;">
                        @if (filled($renderedTemplate['expires_at'] ?? null)) Expires: {{ $renderedTemplate['expires_at'] }}@endif
                        @if (filled($renderedTemplate['expires_at'] ?? null) && filled($renderedTemplate['usage_limit'] ?? null)) · @endif
                        @if (filled($renderedTemplate['usage_limit'] ?? null))Usage limit: {{ $renderedTemplate['usage_limit'] }}@endif
                    </td></tr>
                @endif
                @if (filled($renderedTemplate['footer_note'] ?? null))
                    <tr><td style="padding:18px 36px 28px;font-size:14px;line-height:23px;font-style:italic;color:#5f5f5f;">{{ $renderedTemplate['footer_note'] }}</td></tr>
                @endif
                @if ($supportHref || $socials->isNotEmpty())
                    <tr><td style="padding:26px 36px;border-top:1px solid #e5e7eb;">
                        @if ($supportHref)
                            <div style="font-size:15px;line-height:24px;color:#525252;"><strong style="color:#171717;">HELP</strong><br>Having trouble? <a href="{{ $supportHref }}" style="color:{{ $primaryColor }};font-weight:700;">Contact our support team</a>.</div>
                        @endif
                        @if ($socials->isNotEmpty())
                            <div style="padding-top:20px;text-align:center;">
                                @foreach ($socials as $label => $url)
                                    <a href="{{ $url }}" style="display:inline-block;margin:0 8px;color:{{ $primaryColor }};font-size:13px;font-weight:700;text-decoration:none;">{{ $label }}</a>
                                @endforeach
                            </div>
                        @endif
                    </td></tr>
                @endif
                <tr><td style="padding:20px 36px;background:#fafafa;text-align:center;font-size:12px;line-height:20px;color:#8a8a8a;">Sent by {{ $storeName }}</td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
