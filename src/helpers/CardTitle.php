<?php

namespace matrixcreate\contentiqimporter\helpers;

/**
 * Derives the plain-text native title of a standalone card entry from a
 * ContentIQ card heading. Pure — no Craft dependency.
 *
 * @author  Matrix Create
 * @since   1.38.0
 */
class CardTitle
{
    /** Craft's native title column limit. */
    public const MAX_LENGTH = 255;

    /**
     * Reduces a card heading (`{level, text}`, a bare string, or null) to plain
     * text: tags stripped, entities decoded, whitespace collapsed, capped at
     * {@see MAX_LENGTH} characters. Returns '' when nothing usable remains.
     *
     * @param mixed $heading The card's `heading` value from the export.
     * @return string
     */
    public static function plainText(mixed $heading): string
    {
        $text = '';

        if (is_array($heading)) {
            $text = (string)($heading['text'] ?? '');
        } elseif (is_string($heading)) {
            $text = $heading;
        }

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Decoding can reintroduce characters that were entity-escaped (&nbsp; => U+00A0),
        // so collapse whitespace last; \s with /u covers non-breaking spaces.
        $text = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $text));

        if (mb_strlen($text) > self::MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH));
        }

        return $text;
    }

    /**
     * {@see plainText()} with the `Card {position}` fallback for headings that
     * are missing or empty after cleaning.
     *
     * @param mixed $heading
     * @param int   $position 1-based card position within its block.
     * @return string
     */
    public static function derive(mixed $heading, int $position): string
    {
        $title = self::plainText($heading);

        return $title !== '' ? $title : 'Card ' . $position;
    }
}
