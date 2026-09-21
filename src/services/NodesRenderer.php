<?php

namespace matrixcreate\contentiqimporter\services;

use matrixcreate\contentiqimporter\helpers\UrlSafety;
use yii\base\Component;

/**
 * Converts a ContentIQ nodes array to an HTML string for Craft rich text fields.
 *
 * Supported node types:
 *   - heading (level 1–4) → <h1>–<h4>
 *   - paragraph           → <p>
 *   - blockquote          → <blockquote><p>…</p></blockquote>
 *   - list                → <ul> or <ol> (based on 'ordered' flag)
 *   - ordered_list        → <ol><li> (legacy alias)
 *   - unordered_list      → <ul><li> (legacy alias)
 *   - faq_items           → <details><summary>…</summary><p>…</p></details>
 *   - table               → <table><thead>/<tbody> with <th>/<td> cells
 *   - ctaButton           → <p><a href="url">label</a></p> via render();
 *                           {@see renderSegmented()} is the one path that
 *                           does NOT inline ctaButton nodes as an <a> — it
 *                           returns an ordered {html}/{buttons} segment list
 *                           instead, so a caller (MatrixBuilder's Text block
 *                           handling, gated on the textBlockNestedButtons
 *                           config flag — see defaults.php) can lift button
 *                           runs into CKEditor nested `actionButtons`
 *                           entries. That two-phase save (owner saved with
 *                           prose only, nested entries created against its
 *                           real id, THEN `<craft-entry>` tags spliced back
 *                           in and the owner re-saved) lives in
 *                           ImportService::_writeNestedActionButtons() —
 *                           CKEditor's HTML purifier strips any placeholder
 *                           marker on save, so no id can be inlined before
 *                           the nested entry actually exists. A richText
 *                           field whose CKEditor config doesn't allow the
 *                           `actionButtons` entry type falls back to
 *                           today's inline <a> — see docs/block-mapping.md
 *                           "Text blocks — nested action buttons".
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
     * Renders an array of ContentIQ nodes to an ORDERED LIST OF SEGMENTS,
     * splitting out runs of consecutive ctaButton nodes so a caller can lift
     * them out of the rendered HTML entirely — e.g. MatrixBuilder's Text
     * block handling, which turns a button run into CKEditor nested
     * `actionButtons` entries instead of an inline <a> (see class docblock
     * and docs/block-mapping.md "Text blocks — nested action buttons").
     *
     * Every element is one of:
     *   {html: string}                                   — a run of zero or
     *     more non-button nodes, rendered exactly as render() would (via the
     *     same _renderNode() dispatch — byte-identical output for the same
     *     nodes).
     *   {buttons: [{label, url, target}, ...]}            — a run of one or
     *     more CONSECUTIVE ctaButton nodes. Two button runs separated by
     *     prose are always two separate segments, never merged.
     *
     * A ctaButton node with an empty label AND an empty url contributes
     * NOTHING — not html, not a button, not even a segment boundary — the
     * same skip rule MatrixBuilder::_buildActionButtonsMatrix() applies (not
     * _renderCtaButton()'s own, slightly looser, label-only check: this
     * method's contract is with the nested-entry path, not the inline-<a>
     * one). Dropping it silently rather than emitting an empty {buttons: []}
     * segment means it never splits an otherwise-contiguous prose run in two.
     *
     * render() and _renderCtaButton() are entirely UNCHANGED by this method
     * — every other caller, and the textBlockNestedButtons-disabled/dry-run
     * paths, keep rendering ctaButton nodes as an inline <a> exactly as
     * before.
     *
     * @param array|null $nodes
     * @return array<int, array{html?: string, buttons?: array<int, array{label: string, url: string, target: mixed}>}>
     */
    public function renderSegmented(?array $nodes): array
    {
        if (empty($nodes)) {
            return [];
        }

        $segments  = [];
        $htmlNodes = [];
        $buttonRun = [];

        $flushHtml = function () use (&$segments, &$htmlNodes): void {
            if (empty($htmlNodes)) {
                return;
            }

            $html = '';
            foreach ($htmlNodes as $node) {
                $html .= $this->_renderNode($node);
            }
            $segments[] = ['html' => $html];
            $htmlNodes  = [];
        };

        $flushButtons = function () use (&$segments, &$buttonRun): void {
            if (empty($buttonRun)) {
                return;
            }

            $segments[] = ['buttons' => $buttonRun];
            $buttonRun  = [];
        };

        foreach ($nodes as $node) {
            if (($node['type'] ?? '') === 'ctaButton') {
                $label = (string)($node['label'] ?? '');
                $url   = (string)($node['url'] ?? '');

                if ($label === '' && $url === '') {
                    // Contributes nothing — not a boundary either, so
                    // surrounding prose stays in one html run.
                    continue;
                }

                $flushHtml();
                $buttonRun[] = ['label' => $label, 'url' => $url, 'target' => $node['target'] ?? null];
                continue;
            }

            $flushButtons();
            $htmlNodes[] = $node;
        }

        $flushHtml();
        $flushButtons();

        return $segments;
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

    /**
     * Renders a flat button array ({label, url, target} — the shape
     * {@see renderSegmented()} returns for a 'buttons' segment) back to the
     * same inline <a> HTML render() would have produced for the equivalent
     * ctaButton nodes — reuses _renderCtaButton() directly rather than
     * re-deriving its markup, so the two never drift apart.
     *
     * Used by ImportService::_writeNestedActionButtons()'s fallback path: a
     * richText field whose entry type doesn't allow (or isn't) a CKEditor
     * field falls back to today's inline markup for that button run instead
     * of losing it — see class docblock and docs/block-mapping.md "Text
     * blocks — nested action buttons".
     *
     * @param array<int, array{label: string, url: string, target?: mixed}> $buttons
     * @return string
     */
    public function renderButtonsAsHtml(array $buttons): string
    {
        $html = '';

        foreach ($buttons as $button) {
            $html .= $this->_renderCtaButton([
                'label' => $button['label'] ?? '',
                'url'   => $button['url'] ?? '',
            ]);
        }

        return $html;
    }

    /**
     * Renders a raw ProseMirror document to an HTML string.
     *
     * Unlike render(), which consumes ContentIQ's block-serialised node shape,
     * this consumes the raw `pages.content` ProseMirror AST carried by collection
     * children: a doc node (or bare content array) of block nodes. Handles the
     * node/mark types ContentIQ produces: paragraph, heading (h1–h6 via attrs.level),
     * blockquote, bulletList, orderedList, listItem, hardBreak, and the inline
     * marks bold/italic/link (reusing the shared inline renderer).
     *
     * `horizontalRule` nodes are deliberately NOT rendered — they're a
     * ContentiQ layout aide, never CMS content (see _renderDocNode()). The app
     * strips them at export now, but this is skipped defensively here too, for
     * older app payloads and legacy full-content syncs.
     *
     * `image` nodes (inline images — spec/INLINE-IMAGES-SPEC.md §3.1) render
     * to a `<figure class="image"><img src="{asset:ID:url}" alt="…">
     * [<figcaption>…</figcaption>]</figure>` when the node's `attrs.key`
     * resolves via `$assetIds`; otherwise the node is silently omitted (no
     * warning — this service is pure/stateless, so a missing-key warning is
     * the caller's job, e.g. ImportService::_importCollectionChild()). The
     * `src` is always a Craft reference tag, never the wire's signed URL —
     * see the class-level "no external dependencies" note: this is still
     * true, `{asset:ID:url}` is plain text `craft\htmlfield\HtmlField` parses
     * on save, not a call into any package.
     *
     * @param array|null $doc Raw ProseMirror doc ({type:'doc', content:[...]}) or its content array.
     * @param array      $assetIds Storage key => Craft asset id, for resolving inline image
     *                             nodes. Defaults empty so every pre-existing caller (no inline
     *                             images support yet) stays byte-identical.
     * @return string
     */
    public function renderDocument(?array $doc, array $assetIds = []): string
    {
        if (empty($doc)) {
            return '';
        }

        // Accept either a doc node or a bare content array.
        $nodes = $doc['content'] ?? (isset($doc['type']) ? [] : $doc);

        if (!is_array($nodes)) {
            return '';
        }

        $html = '';
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $html .= $this->_renderDocNode($node, $assetIds);
            }
        }

        return $html;
    }

    /**
     * Extracts the first heading of the given level from a raw ProseMirror doc.
     *
     * Returns the heading's plain text (inline marks stripped) plus the document
     * with that one heading node removed — so it isn't duplicated when the
     * remaining content is rendered to the body field. When no matching heading
     * is found, `text` is null and the doc is returned unchanged. The returned
     * doc keeps the same shape it arrived in (doc node or bare content array).
     *
     * @param array|null $doc Raw ProseMirror doc or its content array.
     * @param int $level Heading level to extract (default 1 — the H1).
     * @return array{text: ?string, doc: array|null}
     */
    public function extractHeading(?array $doc, int $level = 1): array
    {
        if (empty($doc)) {
            return ['text' => null, 'doc' => $doc];
        }

        $nodes = $doc['content'] ?? (isset($doc['type']) ? [] : $doc);

        if (!is_array($nodes)) {
            return ['text' => null, 'doc' => $doc];
        }

        $text = null;
        $kept = [];

        foreach ($nodes as $node) {
            if ($text === null
                && is_array($node)
                && ($node['type'] ?? '') === 'heading'
                && (int)($node['attrs']['level'] ?? 0) === $level
            ) {
                $text = $this->_plainText($node['content'] ?? []);
                continue; // drop this heading from the body
            }

            $kept[] = $node;
        }

        // Nothing matched — return the doc untouched.
        if ($text === null) {
            return ['text' => null, 'doc' => $doc];
        }

        if (isset($doc['content'])) {
            $doc['content'] = $kept;
        } else {
            $doc = $kept;
        }

        return ['text' => $text, 'doc' => $doc];
    }

    // Private Methods
    // =========================================================================

    /**
     * Flattens ProseMirror inline content to plain text, dropping marks.
     *
     * Recurses through any nested `content` (e.g. nodes that wrap inline runs)
     * and concatenates `text`. Used to lift a heading into a plain-text field.
     *
     * @param array $inlineNodes
     * @return string
     */
    private function _plainText(array $inlineNodes): string
    {
        $text = '';

        foreach ($inlineNodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            if (isset($node['text'])) {
                $text .= $node['text'];
            } elseif (isset($node['content']) && is_array($node['content'])) {
                $text .= $this->_plainText($node['content']);
            }
        }

        return trim($text);
    }

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
            'blockquote'     => $this->_renderBlockquote($node),
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
     * Renders a single raw-ProseMirror block node to HTML.
     *
     * Unknown node types are silently skipped.
     *
     * @param array $node
     * @param array $assetIds Storage key => Craft asset id, for the 'image' arm — see renderDocument().
     * @return string
     */
    private function _renderDocNode(array $node, array $assetIds = []): string
    {
        $type = $node['type'] ?? '';

        return match ($type) {
            'heading'        => $this->_renderDocHeading($node),
            'paragraph'      => '<p>' . $this->_renderInlineContent($node['content'] ?? []) . '</p>',
            'blockquote'     => $this->_renderDocBlockquote($node),
            'bulletList'     => $this->_renderDocList($node, 'ul'),
            'orderedList'    => $this->_renderDocList($node, 'ol'),
            'listItem'       => '<li>' . $this->_renderListItem($node) . '</li>',
            // horizontalRule nodes are a ContentiQ layout aide, not CMS
            // content — they must never reach the CMS. The app strips them
            // at export now, but this arm skips them defensively too, for
            // older app payloads and legacy full-content syncs. Listed
            // explicitly (rather than left to `default`) so the intent is
            // visible next to the other node types.
            'horizontalRule' => '',
            'hardBreak'      => '<br>',
            // Inline images (spec/INLINE-IMAGES-SPEC.md §3.1) are top-level
            // only by design (Tiptap emits them as a block-level atom
            // directly under the doc) — only the top-level dispatch from
            // renderDocument()'s own loop passes $assetIds here. A nested
            // occurrence (blockquote/list recursion below calls this method
            // without $assetIds) resolves against the default empty map and
            // is silently omitted, same as any other out-of-scope shape —
            // deliberately NOT threaded into _renderDocBlockquote()/
            // _renderDocList()/_renderListItem() to avoid adding recursion
            // that goes looking for images where the wire contract says
            // they never occur.
            'image'          => $this->_renderDocImage($node, $assetIds),
            default          => '',
        };
    }

    /**
     * Renders a raw ProseMirror heading node to <h1>–<h6> (level from attrs.level).
     *
     * @param array $node
     * @return string
     */
    private function _renderDocHeading(array $node): string
    {
        $level = (int)($node['attrs']['level'] ?? 1);
        $level = max(1, min(6, $level));

        return "<h{$level}>" . $this->_renderInlineContent($node['content'] ?? []) . "</h{$level}>";
    }

    /**
     * Renders a raw ProseMirror `image` node (inline images —
     * spec/INLINE-IMAGES-SPEC.md §3.1) to a `<figure>`.
     *
     * `src` is always a Craft reference tag (`{asset:ID:url}`), resolved from
     * `attrs.key` via `$assetIds` — never the wire's 24h signed URL (that
     * would render for a day, then break, and would never establish the
     * Craft asset relation `craftcms/ckeditor`'s `updateReferences()` needs).
     * A key not present in `$assetIds` (not imported this run, or a dry run
     * where no ids exist yet) silently omits the figure — no warning here,
     * this service is pure/stateless; the caller (ImportService) raises the
     * warning on a real run.
     *
     * `<figcaption>` text is the caption, with " (credit)" appended when
     * credit is also non-empty; when caption is empty but credit is not, the
     * credit stands alone. The `<figcaption>` element itself is omitted
     * entirely when both are empty — never an empty element.
     *
     * @param array $node
     * @param array $assetIds Storage key => Craft asset id.
     * @return string
     */
    private function _renderDocImage(array $node, array $assetIds): string
    {
        $key = (string)($node['attrs']['key'] ?? '');

        if ($key === '' || !isset($assetIds[$key])) {
            return '';
        }

        $assetId = (int)$assetIds[$key];
        $alt     = htmlspecialchars((string)($node['attrs']['alt'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $caption = trim((string)($node['attrs']['caption'] ?? ''));
        $credit  = trim((string)($node['attrs']['credit'] ?? ''));

        $captionText = match (true) {
            $caption !== '' && $credit !== '' => "{$caption} ({$credit})",
            $caption !== ''                   => $caption,
            default                           => $credit,
        };

        $figcaption = $captionText !== ''
            ? '<figcaption>' . htmlspecialchars($captionText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</figcaption>'
            : '';

        return "<figure class=\"image\"><img src=\"{asset:{$assetId}:url}\" alt=\"{$alt}\">{$figcaption}</figure>";
    }

    /**
     * Renders a raw ProseMirror blockquote node to <blockquote>.
     *
     * A blockquote wraps one or more block children (paragraphs, lists, etc.).
     * Each child is rendered through the block-node renderer so nested markup
     * (e.g. a list inside the quote) keeps its own tags. Returns an empty string
     * when the blockquote produces no inner content.
     *
     * @param array $node
     * @return string
     */
    private function _renderDocBlockquote(array $node): string
    {
        $inner = '';

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $inner .= $this->_renderDocNode($child);
            }
        }

        return $inner === '' ? '' : "<blockquote>{$inner}</blockquote>";
    }

    /**
     * Renders a raw ProseMirror bulletList/orderedList to <ul>/<ol>.
     *
     * @param array  $node
     * @param string $tag 'ul' or 'ol'
     * @return string
     */
    private function _renderDocList(array $node, string $tag): string
    {
        $items = '';
        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child) && ($child['type'] ?? '') === 'listItem') {
                $items .= '<li>' . $this->_renderListItem($child) . '</li>';
            }
        }

        return $items === '' ? '' : "<{$tag}>{$items}</{$tag}>";
    }

    /**
     * Renders the inner content of a listItem.
     *
     * A listItem holds block children (typically a paragraph, possibly a nested
     * list). Paragraph children are unwrapped to inline content so the output is
     * clean `<li>text</li>`; nested lists recurse via the block renderer.
     *
     * @param array $node
     * @return string
     */
    private function _renderListItem(array $node): string
    {
        $html = '';
        foreach ($node['content'] ?? [] as $child) {
            if (!is_array($child)) {
                continue;
            }
            if (($child['type'] ?? '') === 'paragraph') {
                $html .= $this->_renderInlineContent($child['content'] ?? []);
            } else {
                $html .= $this->_renderDocNode($child);
            }
        }

        return $html;
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
     * Renders a blockquote node to <blockquote><p>…</p></blockquote>.
     *
     * Uses inline content (with marks) when present, otherwise falls back to plain
     * text — same idiom as _renderParagraph(). CKEditor's Block Quote feature
     * expects a paragraph inside the blockquote, not bare inline content. Returns
     * an empty string for an empty blockquote so nothing is rendered.
     *
     * @param array $node
     * @return string
     */
    private function _renderBlockquote(array $node): string
    {
        $inner = isset($node['content']) && is_array($node['content'])
            ? $this->_renderInlineContent($node['content'])
            : htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($inner === '') {
            return '';
        }

        return "<blockquote><p>{$inner}</p></blockquote>";
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
            'smaller'   => "<span class=\"smaller\">{$inner}</span>",
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
        $safeUrl = UrlSafety::safeHref((string)($mark['attrs']['href'] ?? ''));
        $href    = htmlspecialchars($safeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $target  = $mark['attrs']['target'] ?? '';
        $rel     = $mark['attrs']['rel'] ?? '';

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
        $url   = htmlspecialchars(UrlSafety::safeHref((string)($node['url'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($label === '') {
            return '';
        }

        // `not-prose` opts this button out of the rich-text prose styling. Unlike
        // template-rendered buttons (button.twig adds not-prose), this <a> is
        // embedded in CKEditor HTML where prose styles would otherwise restyle it.
        return "<p><a href=\"{$url}\" class=\"btn btn-primary not-prose\">{$label}</a></p>";
    }
}
