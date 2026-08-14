<?php

namespace matrixcreate\contentiqimporter\helpers;

/**
 * Validates a ContentIQ import temp filename posted back from the browser.
 *
 * The CP upload flow round-trips a server-generated filename through a
 * hidden form field (`tempFilename`) between the preview and run-import
 * requests (see CpController::actionPreview() / actionRunImport()). Without
 * validation, an attacker-controlled value reaching
 * `getTempPath() . '/' . $tempFilename` would allow path traversal (`../`)
 * to read or delete arbitrary files. basename() strips any directory
 * component; the pattern check additionally confirms what's left still
 * looks like a filename this plugin generated.
 *
 * Pure and Craft-free — safe to unit test without booting Craft.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.16.0
 */
class TempFileSafety
{
    // Public Methods
    // =========================================================================

    /**
     * Strips any directory component from $rawFilename and returns the
     * result if it matches the server-generated pattern
     * (`contentiq-import-*.json`), or null otherwise.
     *
     * @param string $rawFilename Raw, untrusted value (e.g. straight from POST).
     * @return string|null The sanitised basename, or null if invalid.
     */
    public static function sanitize(string $rawFilename): ?string
    {
        $base = basename($rawFilename);

        return self::isValid($base) ? $base : null;
    }

    /**
     * Whether $filename — expected to already be a bare basename, no
     * directory separators — matches the server-generated temp filename
     * pattern: `contentiq-import-` + one or more word characters + `.json`.
     *
     * @param string $filename
     * @return bool
     */
    public static function isValid(string $filename): bool
    {
        return (bool)preg_match('/^contentiq-import-[A-Za-z0-9_]+\.json$/', $filename);
    }
}
