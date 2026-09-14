<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0;background:#f4f5f8;color:#172033;font-family:Arial,sans-serif;font-size:16px;line-height:1.6">
<table role="presentation" style="width:100%;border-collapse:collapse"><tr><td style="padding:32px 16px">
<table role="presentation" style="width:100%;max-width:600px;margin:auto;background:#fff;border-radius:16px;border:1px solid #e2e8f0"><tr><td style="padding:32px">
@if ($preview)<p style="font-size:14px;color:#92400e">DEMO PREVIEW — no invitation will be submitted from this preview.</p>@endif
<p style="font-size:18px;font-weight:bold;color:{{ $accent }}">{{ $storeName }}</p>
<h1 style="font-size:24px;line-height:1.35;margin:24px 0">{{ $subject }}</h1>
<p style="font-weight:bold">{{ $productName }}</p>
<div style="white-space:pre-line">{{ $body }}</div>
<p style="margin:32px 0"><a href="{{ $reviewUrl }}" style="display:inline-block;padding:14px 24px;background:{{ $accent }};color:#fff;text-decoration:none;border-radius:8px;font-weight:bold">{{ $buttonLabel }}</a></p>
<p style="font-size:14px;color:#64748b">All ratings are welcome. Photos and videos are optional.</p>
<p style="font-size:14px;color:#64748b"><a href="{{ $unsubscribeUrl }}" style="color:#64748b">Stop review invitations from this store</a></p>
</td></tr></table></td></tr></table></body></html>
