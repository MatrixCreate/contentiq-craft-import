<?php

namespace matrixcreate\contentiqimporter\helpers;

/**
 * Pure (Craft-free) mapping for the Entry Cards manual-card migration
 * (`contentiq-importer/cards/migrate`): builds field values for a new
 * standalone Card entry from a legacy nested card.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.38.0
 */
class CardMigrationMapper
{
    public const TITLE_MAX = 255;

    /**
     * Heading HTML -> single-line plain title (tags stripped, entities decoded, whitespace collapsed).
     */
    public static function htmlToTitle(?string $html): string
    {
        // Tags become spaces first so "<h4>Foo<br>Bar</h4>" reads "Foo Bar", not "FooBar".
        $text = html_entity_decode(
            strip_tags((string)preg_replace('/<[^>]+>/', ' ', (string)$html)),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        return trim((string)preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Plain multiline text -> HTML paragraphs. Input already starting with "<" is returned unchanged.
     */
    public static function textToHtml(string $text): string
    {
        if (str_starts_with(ltrim($text), '<')) {
            return $text;
        }

        $out = '';
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out .= '<p>' . htmlspecialchars($line, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            }
        }

        return $out;
    }

    /**
     * Title fallback chain: heading text, then linked element title, then "Card {n}". Capped at 255 chars.
     */
    public static function cardTitle(?string $titleHtml, ?string $linkedTitle, int $position): string
    {
        $title = self::htmlToTitle($titleHtml);
        if ($title === '') {
            $title = self::htmlToTitle($linkedTitle);
        }
        if ($title === '') {
            $title = 'Card ' . $position;
        }

        return mb_strlen($title) > self::TITLE_MAX ? mb_substr($title, 0, self::TITLE_MAX) : $title;
    }

    /**
     * Field values for a new Card entry. Plain-text card text is wrapped in <p>; only the first image is kept.
     *
     * Input keys: titleHtml, textHtml, linkedTitle, position, imageIds (int[]), link (serialized link field value).
     *
     * @return array{title: string, cardText: string, cardImage: int[], cardLink: array}
     */
    public static function cardFields(array $in): array
    {
        $ids = array_values(array_unique(array_map('intval', $in['imageIds'] ?? [])));

        return [
            'title'     => self::cardTitle($in['titleHtml'] ?? null, $in['linkedTitle'] ?? null, (int)($in['position'] ?? 1)),
            'cardText'  => self::textToHtml((string)($in['textHtml'] ?? '')),
            'cardImage' => array_slice($ids, 0, 1),
            'cardLink'  => array_values($in['link'] ?? []),
        ];
    }
}
