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

    /**
     * Builds the serialized Hyper Url link the import writes for a ContentiQ button.
     *
     * @param  string      $label   Button text.
     * @param  string      $url     Destination URL (may be empty — see {@see hyperInertUrl()}).
     * @param  mixed       $target  The button's `target` value from the export (e.g. `'_blank'`, null).
     * @return array<string, mixed>
     */
    public static function hyperUrlLink(string $label, string $url, mixed $target = null): array
    {
        return [
            'type'       => 'verbb\\hyper\\links\\Url',
            'handle'     => 'default-verbb-hyper-links-url',
            'linkValue'  => self::hyperInertUrl($url),
            'linkText'   => $label,
            'linkClass'  => 'btn btn-primary',
            'newWindow'  => self::opensInNewWindow($target),
        ];
    }

    /**
     * Whether a ContentiQ button's `target` value means "open in a new window".
     *
     * True for the string `'_blank'` (case-insensitive, trimmed) or boolean
     * `true`; false for everything else (including null, missing, `'_self'`).
     * Always resolves to a boolean — a `false` on re-sync must clear a
     * previously set Hyper `newWindow` flag (whole-page replace semantics).
     *
     * @param  mixed  $target  The raw `target` value from the export.
     * @return bool
     */
    public static function opensInNewWindow(mixed $target): bool
    {
        if ($target === true) {
            return true;
        }

        if (is_string($target)) {
            return strtolower(trim($target)) === '_blank';
        }

        return false;
    }
}
