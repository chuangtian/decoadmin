<?php

namespace App\Services\Feishu;

class CampaignPlanningDocumentRenderer
{
    /** @var array<string, array<string, mixed>> */
    private array $blocksById = [];

    /** @var array<string, string> */
    private array $assetUrls = [];

    /** @var array<string, bool> */
    private array $rendered = [];

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  array<string, string>  $assetUrlsByToken
     * @return array{html: string, plain_text: string}
     */
    public function render(?string $title, array $blocks, array $assetUrlsByToken): array
    {
        $this->blocksById = [];
        $this->assetUrls = $assetUrlsByToken;
        $this->rendered = [];

        foreach ($blocks as $block) {
            $blockId = trim((string) ($block['block_id'] ?? ''));

            if ($blockId !== '') {
                $this->blocksById[$blockId] = $block;
            }
        }

        $body = '';
        $root = collect($this->blocksById)->first(
            fn (array $block): bool => (int) ($block['block_type'] ?? 0) === 1,
        );

        if (is_array($root)) {
            $body = $this->renderChildren($root['children'] ?? []);
        } else {
            foreach ($this->blocksById as $blockId => $block) {
                $parentId = trim((string) ($block['parent_id'] ?? ''));

                if ($parentId === '' || ! isset($this->blocksById[$parentId])) {
                    $body .= $this->renderBlock($blockId);
                }
            }
        }

        $heading = filled($title)
            ? '<h1 class="campaign-planning-document__title">'.$this->escape((string) $title).'</h1>'
            : '';
        $html = '<article class="campaign-planning-document">'.$heading.$body.'</article>';
        $plainText = html_entity_decode(strip_tags(str_replace(
            ['</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '</li>', '</blockquote>', '<br>'],
            ["</p>\n", "</h1>\n", "</h2>\n", "</h3>\n", "</h4>\n", "</h5>\n", "</h6>\n", "</li>\n", "</blockquote>\n", "\n"],
            $html,
        )), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plainText = preg_replace("/[\t ]+\n/u", "\n", $plainText) ?? $plainText;
        $plainText = preg_replace("/\n{3,}/u", "\n\n", $plainText) ?? $plainText;

        return ['html' => $html, 'plain_text' => trim($plainText)];
    }

    private function renderBlock(string $blockId): string
    {
        if (isset($this->rendered[$blockId]) || ! isset($this->blocksById[$blockId])) {
            return '';
        }

        $this->rendered[$blockId] = true;
        $block = $this->blocksById[$blockId];
        $type = (int) ($block['block_type'] ?? 0);
        $children = $this->renderChildren($block['children'] ?? []);

        if ($type === 2) {
            return '<p>'.$this->blockText($block, 'text').'</p>'.$children;
        }

        if ($type >= 3 && $type <= 11) {
            $level = min(6, $type - 2);

            return "<h{$level}>".$this->blockText($block, 'heading'.($type - 2))."</h{$level}>".$children;
        }

        return match ($type) {
            12 => '<ul><li>'.$this->blockText($block, 'bullet').$children.'</li></ul>',
            13 => '<ol><li>'.$this->blockText($block, 'ordered').$children.'</li></ol>',
            14 => '<pre><code>'.$this->plainBlockText($block, 'code').'</code></pre>'.$children,
            15 => '<blockquote>'.$this->blockText($block, 'quote').$children.'</blockquote>',
            17 => $this->renderTodo($block, $children),
            19 => '<aside class="campaign-planning-document__callout">'.$children.'</aside>',
            22 => '<hr>',
            23 => $this->renderFile($block).$children,
            24 => '<div class="campaign-planning-document__grid">'.$children.'</div>',
            25 => '<div class="campaign-planning-document__grid-column">'.$children.'</div>',
            27 => $this->renderImage($block).$children,
            31 => $this->renderTable($block),
            32 => '<td>'.$children.'</td>',
            34 => '<blockquote>'.$children.'</blockquote>',
            default => $children,
        };
    }

    private function renderChildren(mixed $children): string
    {
        if (! is_array($children)) {
            return '';
        }

        $html = '';

        foreach ($children as $child) {
            $childId = is_string($child) ? trim($child) : '';

            if ($childId !== '') {
                $html .= $this->renderBlock($childId);
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $block */
    private function renderTodo(array $block, string $children): string
    {
        $done = (bool) data_get($block, 'todo.style.done', false);

        return '<div class="campaign-planning-document__todo">'
            .'<input type="checkbox" disabled'.($done ? ' checked' : '').'>'
            .'<span>'.$this->blockText($block, 'todo').'</span>'.$children.'</div>';
    }

    /** @param array<string, mixed> $block */
    private function renderImage(array $block): string
    {
        $token = trim((string) data_get($block, 'image.token', ''));
        $url = $this->assetUrls[$token] ?? null;

        if (! is_string($url) || $url === '') {
            return '<div class="campaign-planning-document__asset-missing">[图片暂不可用]</div>';
        }

        $caption = $this->elements(data_get($block, 'image.caption.elements', []));

        return '<figure><img loading="lazy" src="'.$this->escape($url).'" alt="">'
            .($caption !== '' ? '<figcaption>'.$caption.'</figcaption>' : '')
            .'</figure>';
    }

    /** @param array<string, mixed> $block */
    private function renderFile(array $block): string
    {
        $token = trim((string) data_get($block, 'file.token', ''));
        $url = $this->assetUrls[$token] ?? null;
        $name = trim((string) data_get($block, 'file.name', '附件')) ?: '附件';

        if (! is_string($url) || $url === '') {
            return '<div class="campaign-planning-document__asset-missing">['.$this->escape($name).' 暂不可用]</div>';
        }

        return '<p class="campaign-planning-document__file"><a href="'.$this->escape($url).'">'
            .$this->escape($name).'</a></p>';
    }

    /** @param array<string, mixed> $block */
    private function renderTable(array $block): string
    {
        $cells = data_get($block, 'table.cells', []);
        $columns = max(1, (int) data_get($block, 'table.property.column_size', 1));

        if (! is_array($cells) || $cells === []) {
            return '<table><tbody>'.$this->renderChildren($block['children'] ?? []).'</tbody></table>';
        }

        $html = '<table><tbody>';

        foreach (array_chunk($cells, $columns) as $row) {
            $html .= '<tr>';

            foreach ($row as $cellId) {
                $cellId = is_string($cellId) ? trim($cellId) : '';

                if ($cellId !== '' && isset($this->blocksById[$cellId])) {
                    $this->rendered[$cellId] = true;
                    $html .= '<td>'.$this->renderChildren($this->blocksById[$cellId]['children'] ?? []).'</td>';
                } else {
                    $html .= '<td></td>';
                }
            }

            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    /** @param array<string, mixed> $block */
    private function blockText(array $block, string $key): string
    {
        return $this->elements(data_get($block, "{$key}.elements", []));
    }

    /** @param array<string, mixed> $block */
    private function plainBlockText(array $block, string $key): string
    {
        $text = html_entity_decode(strip_tags($this->blockText($block, $key)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->escape($text);
    }

    private function elements(mixed $elements): string
    {
        if (! is_array($elements)) {
            return '';
        }

        $html = '';

        foreach ($elements as $element) {
            if (! is_array($element)) {
                continue;
            }

            if (isset($element['text_run']) && is_array($element['text_run'])) {
                $html .= $this->textRun($element['text_run']);

                continue;
            }

            foreach (['mention_user', 'mention_doc', 'reminder', 'equation'] as $type) {
                if (! isset($element[$type]) || ! is_array($element[$type])) {
                    continue;
                }

                $value = $element[$type];
                $text = trim((string) ($value['title'] ?? $value['text'] ?? $value['content'] ?? ''));
                $url = trim((string) ($value['url'] ?? ''));
                $html .= $this->safeLink($url, $text !== '' ? $text : '@');

                continue 2;
            }
        }

        return $html;
    }

    /** @param array<string, mixed> $textRun */
    private function textRun(array $textRun): string
    {
        $html = $this->escape((string) ($textRun['content'] ?? ''));
        $style = $textRun['text_element_style'] ?? [];

        if (! is_array($style)) {
            return $html;
        }

        if ((bool) ($style['inline_code'] ?? false)) {
            $html = '<code>'.$html.'</code>';
        }

        if ((bool) ($style['bold'] ?? false)) {
            $html = '<strong>'.$html.'</strong>';
        }

        if ((bool) ($style['italic'] ?? false)) {
            $html = '<em>'.$html.'</em>';
        }

        if ((bool) ($style['underline'] ?? false)) {
            $html = '<u>'.$html.'</u>';
        }

        if ((bool) ($style['strikethrough'] ?? false)) {
            $html = '<s>'.$html.'</s>';
        }

        $link = data_get($style, 'link.url');

        return is_string($link) && $link !== '' ? $this->safeLink($link, $html, escaped: true) : $html;
    }

    private function safeLink(string $url, string $text, bool $escaped = false): string
    {
        $text = $escaped ? $text : $this->escape($text);
        $url = trim($url);

        if (! preg_match('#^https?://#i', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return $text;
        }

        return '<a href="'.$this->escape($url).'" target="_blank" rel="noopener noreferrer">'.$text.'</a>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
