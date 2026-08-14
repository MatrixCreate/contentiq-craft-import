<?php

namespace matrixcreate\contentiqimporter\helpers;

/**
 * Button/link URL helpers for the ContentiQ import.
 *
 * @author  Matrix Create
 * @since   1.0.0
 */
class LinkHelper
{
    // =========================================================================
    // Static Methods
    // =========================================================================

    /**
     * Normalises a ContentiQ button URL into a value Craft's Verbb Hyper field
     * accepts.
     *
     * ContentiQ exports a button with no real destination as `null` (previously
     * a `'https://'` placeholder). Hyper rejects `'#'` as an empty value, so all
     * inert markers — `null`, `''`, `'#'`, and the bare schemes `'https://'` /
     * `'http://'` — collapse to the `'https://'` placeholder that Hyper accepts,
     * letting an editor set the real destination in the CMS after import. Real
     * URLs (including relative paths) pass through, trimmed.
     *
     * @param  mixed  $url  The raw URL from the export (string, null, or missing).
     * @return string       A Hyper-safe link value.
     */
    public static function hyperInertUrl(mixed $url): string
    {
        $trimmed = is_string($url) ? trim($url) : '';

        if ($trimmed === '' || $trimmed === '#' || $trimmed === 'https://' || $trimmed === 'http://') {
            return 'https://';
        }

        // A disallowed scheme (javascript:, data:, vbscript:, …) collapses to
        // the same inert placeholder as an empty URL — it must never reach the
        // Hyper field, which would render it as a clickable stored-XSS link.
        if (UrlSafety::safeHref($trimmed) === '#') {
            return 'https://';
        }

        return $trimmed;
    }
}
