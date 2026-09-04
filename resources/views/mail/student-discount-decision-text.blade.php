@forelse (($renderedTemplate['content_blocks'] ?? []) as $block)
@if (in_array($block['type'] ?? '', ['heading', 'paragraph', 'note'], true) && filled($block['text'] ?? null))
{{ $block['text'] }}

@elseif (($block['type'] ?? '') === 'discount_code' && ($renderedTemplate['type'] ?? '') === 'approval' && filled($renderedTemplate['discount_code'] ?? null))
Discount code: {{ $renderedTemplate['discount_code'] }}

@elseif (($block['type'] ?? '') === 'button' && filled($block['text'] ?? null))
{{ $block['text'] }}: {{ filled($block['url'] ?? null) ? $block['url'] : ($renderedTemplate['branding']['shop_url'] ?? '') }}

@endif
@empty
{{ $renderedTemplate['heading'] ?? '' }}

{{ $renderedTemplate['body'] ?? '' }}
@endforelse
@if (filled($renderedTemplate['branding']['support_url'] ?? null))

Support: {{ $renderedTemplate['branding']['support_url'] }}
@elseif (filled($renderedTemplate['branding']['support_email'] ?? null))

Support: {{ $renderedTemplate['branding']['support_email'] }}
@endif
