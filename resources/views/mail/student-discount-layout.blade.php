@php
    $branding = $renderedTemplate['branding'] ?? [];
    $primaryColor = $branding['primary_color'] ?? '#111111';
    $storeName = $renderedTemplate['store_name'] ?? config('app.name');
    $isApproval = ($renderedTemplate['type'] ?? '') === 'approval';
    $contentBlocks = collect($renderedTemplate['content_blocks'] ?? []);
    $supportHref = filled($branding['support_url'] ?? null)
        ? $branding['support_url']
        : (filled($branding['support_email'] ?? null) ? 'mailto:'.$branding['support_email'] : null);
    $socials = collect([
        ['label' => 'Instagram', 'url' => $branding['instagram_url'] ?? '', 'icon' => 'images/email/social/instagram.png'],
        ['label' => 'Facebook', 'url' => $branding['facebook_url'] ?? '', 'icon' => 'images/email/social/facebook.png'],
        ['label' => 'TikTok', 'url' => $branding['tiktok_url'] ?? '', 'icon' => 'images/email/social/tiktok.png'],
        ['label' => 'YouTube', 'url' => $branding['youtube_url'] ?? '', 'icon' => 'images/email/social/youtube.png'],
    ])->filter(fn (array $social): bool => filled($social['url']));
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
                @forelse ($contentBlocks as $block)
                    @php
                        $blockType = $block['type'] ?? 'paragraph';
                        $align = $block['align'] ?? 'left';
                        $fontSize = (int) ($block['font_size'] ?? 17);
                        $lineHeight = $fontSize + max(8, (int) round($fontSize * 0.45));
                        $weight = ! empty($block['bold']) ? '800' : '400';
                        $fontStyle = ! empty($block['italic']) ? 'italic' : 'normal';
                        $decoration = ! empty($block['underline']) ? 'underline' : 'none';
                        $color = $block['color'] ?? '#404040';
                    @endphp
                    @if (in_array($blockType, ['heading', 'paragraph', 'note'], true) && filled($block['text'] ?? null))
                        <tr><td style="padding:{{ $blockType === 'heading' ? '32px 36px 8px' : ($blockType === 'note' ? '12px 36px 26px' : '10px 36px') }};font-size:{{ $fontSize }}px;line-height:{{ $lineHeight }}px;font-weight:{{ $weight }};font-style:{{ $fontStyle }};text-decoration:{{ $decoration }};text-align:{{ $align }};color:{{ $color }};">{!! nl2br(e($block['text'])) !!}</td></tr>
                    @elseif ($blockType === 'discount_code' && $isApproval && filled($renderedTemplate['discount_code'] ?? null))
                        <tr><td style="padding:20px 36px 8px;"><div style="border:3px solid {{ $primaryColor }};padding:26px 20px;text-align:center;font-family:'Courier New',monospace;font-size:27px;line-height:34px;font-weight:800;letter-spacing:2px;color:#111111;word-break:break-all;">{{ $renderedTemplate['discount_code'] }}</div></td></tr>
                    @elseif ($blockType === 'button' && filled($block['text'] ?? null) && (filled($block['url'] ?? null) || filled($branding['shop_url'] ?? null)))
                        @php $buttonHref = filled($block['url'] ?? null) ? $block['url'] : $branding['shop_url']; @endphp
                        <tr><td align="{{ $align }}" style="padding:14px 36px 8px;text-align:{{ $align }};"><a href="{{ $buttonHref }}" rel="noopener noreferrer" style="display:{{ ($block['width'] ?? 'full') === 'full' ? 'block' : 'inline-block' }};padding:18px 24px;background:{{ $block['background_color'] ?? $primaryColor }};color:{{ $color }};text-align:center;text-decoration:{{ $decoration }};font-size:{{ $fontSize }}px;line-height:{{ $lineHeight }}px;font-weight:{{ $weight }};font-style:{{ $fontStyle }};">{{ $block['text'] }}</a></td></tr>
                    @elseif ($blockType === 'divider')
                        <tr><td style="padding:{{ (int) ($block['spacing'] ?? 20) }}px 36px;"><div style="border-top:1px solid {{ $block['color'] ?? '#E5E7EB' }};font-size:0;line-height:0;">&nbsp;</div></td></tr>
                    @elseif ($blockType === 'spacer')
                        <tr><td height="{{ (int) ($block['spacing'] ?? 20) }}" style="height:{{ (int) ($block['spacing'] ?? 20) }}px;font-size:0;line-height:0;">&nbsp;</td></tr>
                    @endif
                @empty
                    <tr><td style="padding:34px 36px 10px;">
                        <h1 style="margin:0 0 20px;font-size:32px;line-height:40px;font-weight:800;color:#111111;">{{ $renderedTemplate['heading'] ?? '' }}</h1>
                        <div style="font-size:17px;line-height:28px;color:#404040;">{!! nl2br(e($renderedTemplate['body'] ?? '')) !!}</div>
                    </td></tr>
                @endforelse
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
                                @foreach ($socials as $social)
                                    <a href="{{ $social['url'] }}" aria-label="{{ $social['label'] }}" title="{{ $social['label'] }}" style="display:inline-block;margin:0 9px;text-decoration:none;">
                                        <img src="{{ asset($social['icon']) }}" alt="{{ $social['label'] }}" width="28" height="28" style="display:block;width:28px;height:28px;border:0;">
                                    </a>
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
