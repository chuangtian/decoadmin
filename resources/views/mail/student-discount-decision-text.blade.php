{{ $renderedTemplate['heading'] ?? '' }}

{{ $renderedTemplate['body'] ?? '' }}
@if (($renderedTemplate['type'] ?? '') === 'approval' && filled($renderedTemplate['discount_code'] ?? null))

Discount code: {{ $renderedTemplate['discount_code'] }}
@endif
@if (filled($renderedTemplate['branding']['shop_url'] ?? null))

Shop: {{ $renderedTemplate['branding']['shop_url'] }}
@endif
@if (filled($renderedTemplate['footer_note'] ?? null))

{{ $renderedTemplate['footer_note'] }}
@endif
@if (filled($renderedTemplate['branding']['support_url'] ?? null))

Support: {{ $renderedTemplate['branding']['support_url'] }}
@elseif (filled($renderedTemplate['branding']['support_email'] ?? null))

Support: {{ $renderedTemplate['branding']['support_email'] }}
@endif
