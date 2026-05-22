<?php

/**
 * ContentIQ — default block type mappings.
 *
 * Keys are ContentIQ JSON block type strings (exact, from export).
 * Values define the outer Craft Matrix block type and nested inner Matrix structure.
 *
 * Structure per mapping:
 *   outerType   — handle of the Craft contentBlocks entry type
 *   outerFields — fields set directly on the outer entry, keyed by ContentIQ source key:
 *                   [contentiqKey => [craftHandle, handlerType]]
 *   innerMatrix — nested Matrix field config:
 *                   outerField  — Matrix field handle on the outer entry type
 *                   innerType   — inner entry type handle
 *                   mode        — 'single' (one inner entry from block fields)
 *                               | 'repeated' (one inner entry per item in sourceKey array)
 *                               | 'grouped' (consecutive blocks of same type merge into one
 *                                            outer entry with one inner entry per block)
 *                   sourceKey   — (repeated only) ContentIQ field key holding the items array
 *                   fields      — inner entry field mapping: [contentiqKey => [craftHandle, handlerType]]
 *
 * Handler types:
 *   'nodes'           → nodes array → NodesRenderer → HTML string
 *   'image'           → {key, url, alt} object → ImageImportService → asset ID array
 *   'heading'         → {level, text} object or plain string → <hN>text</hN> HTML
 *   'body'            → plain string → <p>text</p> HTML
 *   'layout'          → plain string → pass through as-is
 *   'textMediaLayout' → ContentIQ layout string → mapped to Craft dropdown value
 *   'tableHtml'       → rows array [{isHeader, cells}] → HTML <table> string
 *   'hyperButton'     → {label, url} object → Hyper link field array
 *   'buttonNodes'     → ContentNode[] → filters ctaButton nodes → actionButtons Matrix entries
 *   'faqNodes'        → ContentNode[] → splits at faq_items → richText/extraRichText/_faqItems
 *   'uspContent'      → entire block fields → {heading:{level,text}, items:[string]} → <hN>+<ul> HTML
 *
 * Special contentiqKey '_block':
 *   When the contentiqKey in outerFields is '_block', the entire block fields array is passed
 *   to the handler instead of a single field value. Used for blocks (like USP) where the output
 *   is derived from multiple source keys combined into one Craft field.
 *
 * Developer notes:
 *   'notes' is a block-level key (not inside 'fields') emitted by ContentIQ when the CSM
 *   adds a developer note to a block. It is read directly from $block['notes'] in
 *   ImportService and written to the contentiqNotes field on the outer entry. It is NOT
 *   present in outerFields here because MatrixBuilder reads from $block['fields'] only.
 *
 * Blocks NOT in this mapping (handled or skipped elsewhere):
 *   hero          — imported separately into entry.hero Matrix field (not contentBlocks)
 *   call_to_action — handled separately; creates a callToActionEntry and relates it
 *   table         — no matching block type in this project
 *
 * Override any mapping in config/contentiq.php using the 'blockOverrides' key.
 * Overrides replace the entire block definition (not merged at field level).
 *
 * ---------------------------------------------------------------------------
 * Content types ('content_types' key, below)
 * ---------------------------------------------------------------------------
 * Maps ContentIQ content_type slugs (set on collection children) to the Craft
 * section / entry type / content field they import into. Collection children
 * carry a raw `content` (ProseMirror) field instead of a `blocks` array, which
 * is serialised to HTML and written to `contentField`.
 *
 * Per-slug keys:
 *   section      — Craft section handle
 *   entryType    — Craft entry type handle
 *   contentField — field the serialised ProseMirror HTML is written to
 *   headingField — (optional) field to receive the H1. When set, the first
 *                  level-1 heading is lifted out as plain text into this field
 *                  and removed from the body so it isn't rendered twice.
 *
 * These defaults match the Craft Starter. Override per-project in
 * config/contentiq.php under the 'content_types' key (per-slug replace).
 * A content_type with no matching entry here is logged and skipped (non-fatal).
 *
 * NOTE: 'content_types' is NOT a block mapping — MatrixBuilder excludes it.
 */

return [
    'text' => [
        'outerType'   => 'text',
        'outerFields' => [
            'columns' => ['columnLayout', 'layout'],
        ],
        'innerMatrix' => [
            'outerField' => 'textBlocks',
            'innerType'  => 'textBlock',
            'mode'       => 'text_columns',
            'fields'     => [
                'nodes' => ['richText', 'nodes'],
            ],
        ],
    ],

    'text_and_media' => [
        'outerType'   => 'textAndMedia',
        'outerFields' => [
            'layout' => ['blockLayout', 'textMediaLayout'],
        ],
        'innerMatrix' => [
            'outerField' => 'textAndMediaBlocks',
            'innerType'  => 'textAndMediaBlock',
            'mode'       => 'grouped',
            'fields'     => [
                'nodes' => ['richText', 'nodes'],
                'image' => ['image',    'image'],
            ],
        ],
    ],

    'faq' => [
        'outerType'   => 'faq',
        'outerFields' => [
            // faqNodes splits nodes at faq_items boundary:
            //   before → richText, after → extraRichText, items → _faqItems
            'nodes' => ['richText', 'faqNodes'],
        ],
        'innerMatrix' => [
            'outerField' => 'accordionItems',
            'innerType'  => 'accordionItem',
            'mode'       => 'repeated',
            'sourceKey'         => 'items',       // new: flat array from ContentIQ
            'fallbackSourceKey' => '_faqItems',   // legacy: extracted from nodes.faq_items
            'fields'     => [
                'question' => ['itemTitle',   'heading'],  // plain string → <h3>
                'answer'   => ['itemContent', 'body'],     // plain string → <p>
            ],
        ],
    ],

    'cards' => [
        'outerType'   => 'entryCards',
        'outerFields' => [
            'intro' => ['richText', 'nodes'],  // ContentNode[] → HTML above card grid
        ],
        'innerMatrix' => [
            'outerField' => 'entryCards',
            'innerType'  => 'card',
            'mode'       => 'repeated',
            'sourceKey'  => 'cards',
            'fields'     => [
                'heading' => ['cardTitle',         'heading'],     // {level,text} → <hN>
                'body'    => ['cardText',          'nodes'],       // ContentNode[] → HTML via NodesRenderer
                'image'   => ['cardImage',         'image'],
                'button'  => ['actionButtonLabel', 'buttonLabel'], // {label,url} → label text only
            ],
        ],
    ],

    'price_list' => [
        'outerType'   => 'priceList',
        'outerFields' => [
            'nodes'     => ['richText',      'nodes'],       // intro nodes → CKEditor HTML
            'rows'      => ['priceList',     'tableHtml'],   // rows array → HTML <table>
            'postNodes' => ['actionButtons', 'buttonNodes'], // ctaButton nodes after table → actionButtons Matrix
        ],
        'innerMatrix' => null,
    ],

    'usp' => [
        'outerType'   => 'contentiqUsp',
        'outerFields' => [
            // '_block' passes the entire block fields to the handler.
            // USP API shape: {heading: {level, text}, items: [string, ...]}
            // Falls back to rendering a 'nodes' array if present instead.
            '_block' => ['uspText', 'uspContent'],
        ],
        'innerMatrix' => null,
    ],

    'global' => [
        'outerType'   => 'contentiqGlobal',
        'outerFields' => [
            'nodes' => ['globalContent', 'nodes'],  // rendered as HTML into the globalContent CKEditor field
        ],
        'innerMatrix' => null,
    ],

    'custom' => [
        'outerType'   => 'contentiqCustom',
        'outerFields' => [
            'nodes'  => ['contentiqContent', 'nodes'],   // all content nodes → CKEditor HTML
            'images' => ['contentiqImages',  'images'],  // optional multiple assets (up to 10)
        ],
        'innerMatrix' => null,
    ],

    // Content type routing for collection children (see header note).
    // slug => [section, entryType, contentField]
    'content_types' => [
        'articles' => [
            'section'      => 'articles',
            'entryType'    => 'article',
            'contentField' => 'articleContent',
            'headingField' => 'headline',  // H1 lifted out of the body into this field
        ],
        'blog_categories' => [
            'section'      => 'blogCategories',
            'entryType'    => 'blogCategory',
            'contentField' => 'body',
        ],
        'case_studies' => [
            'section'      => 'caseStudies',
            'entryType'    => 'caseStudy',
            'contentField' => 'articleContent',
        ],
        'team' => [
            'section'      => 'team',
            'entryType'    => 'teamMember',
            'contentField' => 'body',
        ],
        'offices' => [
            'section'      => 'offices',
            'entryType'    => 'office',
            'contentField' => 'body',
        ],
    ],
];
