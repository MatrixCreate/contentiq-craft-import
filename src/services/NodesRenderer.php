<?php

namespace matrixcreate\contentiqimporter\services;

use yii\base\Component;

/**
 * Converts a ContentIQ nodes array to an HTML string for Craft rich text fields.
 *
 * Supported node types:
 *   - heading (level 1–4) → <h1>–<h4>
 *   - paragraph           → <p>
 *   - list                → <ul> or <ol> (based on 'ordered' flag)
 *   - ordered_list        → <ol><li> (legacy alias)
 *   - unordered_list      → <ul><li> (legacy alias)
 *   - faq_items           → <details><summary>…</summary><p>…</p></details>
 *   - table               → <table><thead>/<tbody> with <th>/<td> cells
 *   - ctaButton           → <p><a href="url">label</a></p>
 *
 * No external dependencies. This service is stateless — all methods are pure.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class NodesRenderer extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Renders an array of ContentIQ nodes to an HTML string.
     *
     * Returns an empty string for null or empty input — callers should handle
     * empty-string fields as they see fit.
     *
     * @param array|null $nodes
     * @return string
     */
    public function render(?array $nodes): string
    {
        if (empty($nodes)) {
            return '';
        }

        $html = '';

        foreach ($nodes as $node) {
            $html .= $this->_renderNode($node);
        }

        return $html;
    }

    /**
     * Public access to inline content rendering for other services (e.g. MatrixBuilder).
     *
     * @param array $inlineNodes
     * @return string
     */
    public function renderInlineContent(array $inlineNodes): string
    {
        return $this->_renderInlineContent($inlineNodes);
    }

    // Private Methods
    // =========================================================================

    /**
     * Renders a single node to HTML.
     *
     * Unknown node types are silently skipped.
     *
     * @param array $node
     * @return string
     */
    private function _renderNode(array $node): string
    {
        $type = $node['type'] ?? '';

        return match ($type) {
            'heading'        => $this->_renderHeading($node),
            'paragraph'      => $this->_renderParagraph($node),
            'list'           => $this->_renderList($node, !empty($node['ordered']) ? 'ol' : 'ul'),
            'ordered_list'   => $this->_renderList($node, 'ol'),
            'unordered_list' => $this->_renderList($node, 'ul'),
            'faq_items'      => $this->_renderFaqItems($node),
            'table'          => $this->_renderTable($node),
            'ctaButton'      => $this->_renderCtaButton($node),
            default          => '',
        };
    }

    /**
     * Renders a heading node to <h1>–<h4>.
     *
     * Clamps level to the range 1–4. Defaults to <h2> if level is missing.
     * Uses inline content (with marks) when present, otherwise falls back to plain text.
     *
     * @param array $node
     * @return string
     */
    private function _renderHeading(array $node): string
    {
        $level = (int)($node['level'] ?? 2);
        $level = max(1, min(4, $level));

        $inner = isset($node['content']) && is_array($node['content'])
            ? $this->_renderInlineContent($node['content'])
            : htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<h{$level}>{$inner}</h{$level}>";
    }

    /**
     * Renders a paragraph node to <p>.
     *
     * Uses inline content (with marks) when present, otherwise falls back to plain text.
     *
     * @param array $node
     * @return string
     */
    private function _renderParagraph(array $node): string
    {
        $inner = isset($node['content']) && is_array($node['content'])
            ? $this->_renderInlineContent($node['content'])
            : htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<p>{$inner}</p>";
    }

    /**
     * Renders an ordered or unordered list node.
     *
     * Uses itemContents (with marks) when present, otherwise falls back to plain text items.
     *
     * @param array  $node
     * @param string $tag  'ol' or 'ul'
     * @return string
     */
    private function _renderList(array $node, string $tag): string
    {
        $items        = $node['items'] ?? [];
        $itemContents = $node['itemContents'] ?? [];

        if (empty($items)) {
            return '';
        }

        $lis = '';

        foreach ($items as $i => $item) {
            if (isset($itemContents[$i]) && is_array($itemContents[$i])) {
                $inner = $this->_renderInlineContent($itemContents[$i]);
            } else {
                $text  = is_string($item) ? $item : ($item['text'] ?? '');
                $inner = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $lis .= "<li>{$inner}</li>";
        }

        return "<{$tag}>{$lis}</{$tag}>";
    }

    /**
     * Renders a faq_items node as details/summary accordion elements.
     *
     * Each FAQ item becomes a <details><summary>question</summary><p>answer</p></details>.
     * CKEditor's Rich Text field supports details/summary natively.
     * Uses questionContent/answerContent (with marks) when present.
     *
     * @param array $node
     * @return string
     */
    private function _renderFaqItems(array $node): string
    {
        $items = $node['faqItems'] ?? [];

        if (empty($items)) {
            return '';
        }

        $html = '';

        foreach ($items as $item) {
            $question = isset($item['questionContent']) && is_array($item['questionContent'])
                ? $this->_renderInlineContent($item['questionContent'])
                : htmlspecialchars($item['question'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $answer = isset($item['answerContent']) && is_array($item['answerContent'])
                ? $this->_renderInlineContent($item['answerContent'])
                : htmlspecialchars($item['answer'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ($question !== '' || $answer !== '') {
                $html .= "<details><summary>{$question}</summary><p>{$answer}</p></details>";
            }
        }

        return $html;
    }

    /**
     * Renders a table node as an HTML table.
     *
     * Rows with isHeader = true are rendered in <thead> using <th> cells.
     * All other rows are rendered in <tbody> using <td> cells.
     * Uses cellContents (with marks) when present.
     *
     * @param array $node
     * @return string
     */
    private function _renderTable(array $node): string
    {
        $rows = $node['tableRows'] ?? [];

        if (empty($rows)) {
            return '';
        }

        $headerRows = array_values(array_filter($rows, fn ($r) => !empty($r['isHeader'])));
        $bodyRows   = array_values(array_filter($rows, fn ($r) => empty($r['isHeader'])));

        $thead = '';
        foreach ($headerRows as $row) {
            $cells        = '';
            $cellContents = $row['cellContents'] ?? [];
            foreach ($row['cells'] ?? [] as $i => $cell) {
                $inner   = isset($cellContents[$i]) && is_array($cellContents[$i])
                    ? $this->_renderInlineContent($cellContents[$i])
                    : htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cells .= "<th>{$inner}</th>";
            }
            $thead .= "<tr>{$cells}</tr>";
        }

        $tbody = '';
        foreach ($bodyRows as $row) {
            $cells        = '';
            $cellContents = $row['cellContents'] ?? [];
            foreach ($row['cells'] ?? [] as $i => $cell) {
                $inner   = isset($cellContents[$i]) && is_array($cellContents[$i])
                    ? $this->_renderInlineContent($cellContents[$i])
                    : htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cells .= "<td>{$inner}</td>";
            }
            $tbody .= "<tr>{$cells}</tr>";
        }

        $html = '<table>';
        if ($thead !== '') {
            $html .= "<thead>{$thead}</thead>";
        }
        if ($tbody !== '') {
            $html .= "<tbody>{$tbody}</tbody>";
        }
        $html .= '</table>';

        return $html;
    }

    /**
     * Renders an InlineNode array to an HTML string.
     *
     * Handles text nodes (with marks) and hardBreak nodes. Marks are applied
     * innermost-first so the first mark in the array becomes the outermost tag.
     *
     * @param array $inlineNodes
     * @return string
     */
    private function _renderInlineContent(array $inlineNodes): string
    {
        $html = '';

        foreach ($inlineNodes as $node) {
            if (($node['type'] ?? '') === 'hardBreak') {
                $html .= '<br>';
                continue;
            }

            $text = htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            foreach (array_reverse($node['marks'] ?? []) as $mark) {
                $text = $this->_wrapMark($mark, $text);
            }

            $html .= $text;
        }

        return $html;
    }

    /**
     * Wraps an HTML string in the appropriate tag for a ProseMirror mark.
     *
     * Unknown mark types pass through unchanged.
     *
     * @param array  $mark
     * @param string $inner
     * @return string
     */
    private function _wrapMark(array $mark, string $inner): string
    {
        return match ($mark['type'] ?? '') {
            'bold'      => "<strong>{$inner}</strong>",
            'italic'    => "<em>{$inner}</em>",
            'code'      => "<code>{$inner}</code>",
            'strike'    => "<s>{$inner}</s>",
            'underline' => "<u>{$inner}</u>",
            'link'      => $this->_wrapLink($mark, $inner),
            default     => $inner,
        };
    }

    /**
     * Wraps an HTML string in an anchor tag using the link mark's href attr.
     *
     * @param array  $mark
     * @param string $inner
     * @return string
     */
    private function _wrapLink(array $mark, string $inner): string
    {
        $href   = htmlspecialchars($mark['attrs']['href'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $target = $mark['attrs']['target'] ?? '';
        $rel    = $mark['attrs']['rel'] ?? '';

        $attrs = "href=\"{$href}\"";
        if ($target !== '' && $target !== null) {
            $attrs .= ' target="' . htmlspecialchars((string)$target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }
        if ($rel !== '' && $rel !== null) {
            $attrs .= ' rel="' . htmlspecialchars((string)$rel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return "<a {$attrs}>{$inner}</a>";
    }

    /**
     * Renders a ctaButton node as an anchor link.
     *
     * URL is always empty from ContentIQ (set by editors in the CMS after import).
     * Wraps in <p> so it occupies its own line in CKEditor output.
     *
     * @param array $node
     * @return string
     */
    private function _renderCtaButton(array $node): string
    {
        $label = htmlspecialchars($node['label'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url   = htmlspecialchars($node['url'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($label === '') {
            return '';
        }

        return "<p><a href=\"{$url}\" class=\"btn btn-primary\">{$label}</a></p>";
    }
}
