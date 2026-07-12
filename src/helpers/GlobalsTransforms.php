<?php

namespace matrixcreate\contentiqimporter\helpers;

/**
 * Pure, dependency-free transforms for the globals import pipeline.
 *
 * Every method here is static and side-effect free so it can be unit tested
 * without a running Craft application (see tests/run-transforms.php). Craft
 * lookups (e.g. the country repository) are injected by the caller.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.3.0
 */
class GlobalsTransforms
{
    // Constants
    // =========================================================================

    /**
     * Wire weekday name → Store Hours day index.
     *
     * Store Hours stores days as 0 = Sunday … 6 = Saturday, regardless of the
     * field's startDay (that setting only affects CP display order). Verified
     * against craft\storehours\data\FieldData (getSun() = [0] … getSat() = [6])
     * and craft\storehours\Field::normalizeValue() which iterates 0..6.
     *
     * @var array<string, int>
     */
    public const DAY_INDEXES = [
        'sunday'    => 0,
        'monday'    => 1,
        'tuesday'   => 2,
        'wednesday' => 3,
        'thursday'  => 4,
        'friday'    => 5,
        'saturday'  => 6,
    ];

    /**
     * Common free-text country aliases → ISO 3166-1 alpha-2, keyed lowercase.
     *
     * @var array<string, string>
     */
    public const COUNTRY_ALIASES = [
        'uk'                       => 'GB',
        'u.k.'                     => 'GB',
        'united kingdom'           => 'GB',
        'great britain'            => 'GB',
        'britain'                  => 'GB',
        'england'                  => 'GB',
        'scotland'                 => 'GB',
        'wales'                    => 'GB',
        'northern ireland'         => 'GB',
        'usa'                      => 'US',
        'u.s.a.'                   => 'US',
        'us'                       => 'US',
        'u.s.'                     => 'US',
        'us of a'                  => 'US',
        'united states'            => 'US',
        'united states of america' => 'US',
        'america'                  => 'US',
        'ireland'                  => 'IE',
        'republic of ireland'      => 'IE',
    ];

    // Static Methods
    // =========================================================================

    /**
     * Resolves a free-text country name (or existing ISO code) to an ISO
     * 3166-1 alpha-2 code, or null when no confident match is found.
     *
     * Matching order: existing valid ISO code → alias table → case-insensitive
     * official name. A null result means "leave the field untouched" — the
     * caller must NOT clear the field on a null.
     *
     * @param string|null $input      Free-text country name or ISO code.
     * @param array<string, string> $codeToName ISO code => official name (injected).
     * @param array<string, string> $aliases    Lowercase alias => ISO code.
     * @return string|null
     */
    public static function countryCode(?string $input, array $codeToName, array $aliases = self::COUNTRY_ALIASES): ?string
    {
        $needle = strtolower(trim((string)$input));

        if ($needle === '') {
            return null;
        }

        // Already a valid ISO alpha-2 code?
        if (strlen($needle) === 2) {
            $upper = strtoupper($needle);

            if (isset($codeToName[$upper])) {
                return $upper;
            }
        }

        // Alias table.
        if (isset($aliases[$needle])) {
            return $aliases[$needle];
        }

        // Case-insensitive official name match.
        foreach ($codeToName as $code => $name) {
            if (strtolower($name) === $needle) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Extracts the first path segment of a slash-delimited path.
     *
     * @param string $path
     * @return string
     */
    public static function firstSegment(string $path): string
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return '';
        }

        $segments = explode('/', $trimmed);

        return $segments[0];
    }

    /**
     * Expands ContentiQ opening-hours groups into a Store Hours per-weekday map.
     *
     * Returns a 7-element array keyed 0 (Sunday) … 6 (Saturday). Each value is
     * ['open' => ?string, 'close' => ?string] where the times are 'HH:MM'
     * strings, or null for a closed/blank slot. Days that are closed, or named
     * in no group, get null slots.
     *
     * The output is intentionally the raw time strings — the service wraps each
     * non-null value in the DateTimeHelper array shape Store Hours expects.
     *
     * @param array $groups ContentiQ opening_hours: [{days[], closed, opens, closes}].
     * @return array<int, array{open: ?string, close: ?string}>
     */
    public static function openingHours(array $groups): array
    {
        $days = [];

        for ($day = 0; $day <= 6; $day++) {
            $days[$day] = ['open' => null, 'close' => null];
        }

        foreach ($groups as $group) {
            $closed = (bool)($group['closed'] ?? false);
            $opens  = $closed ? null : ($group['opens'] ?? null);
            $closes = $closed ? null : ($group['closes'] ?? null);

            foreach ($group['days'] ?? [] as $dayName) {
                $index = self::DAY_INDEXES[strtolower((string)$dayName)] ?? null;

                if ($index === null) {
                    continue;
                }

                $days[$index] = [
                    'open'  => $opens !== null && $opens !== '' ? (string)$opens : null,
                    'close' => $closes !== null && $closes !== '' ? (string)$closes : null,
                ];
            }
        }

        return $days;
    }

    /**
     * Splits a multi-line address into three address lines.
     *
     * Splits on newlines, trims each line, and maps the first three onto
     * addressLine1/2/3. Missing lines become empty strings (sync-owned clear).
     * A single line writes to line 1 only. Any lines beyond the third are folded
     * into line 3, joined with ', '.
     *
     * @param string|null $address
     * @return array{addressLine1: string, addressLine2: string, addressLine3: string}
     */
    public static function splitAddress(?string $address): array
    {
        $lines = [];

        foreach (preg_split('/\r\n|\r|\n/', (string)$address) as $line) {
            $trimmed = trim($line);

            if ($trimmed !== '') {
                $lines[] = $trimmed;
            }
        }

        // Fold any 4th+ lines into the third line.
        if (count($lines) > 3) {
            $overflow = array_splice($lines, 2);
            $lines[2] = implode(', ', $overflow);
        }

        return [
            'addressLine1' => $lines[0] ?? '',
            'addressLine2' => $lines[1] ?? '',
            'addressLine3' => $lines[2] ?? '',
        ];
    }

    /**
     * Returns the leading static path prefix of a section uriFormat — everything
     * before the first `{` token, trimmed of slashes.
     *
     * Examples: 'blog/{slug}' → 'blog'; 'blog/category/{slug}' → 'blog/category';
     * '{slug}' → ''.
     *
     * @param string $uriFormat
     * @return string
     */
    public static function staticUriPrefix(string $uriFormat): string
    {
        $bracePos = strpos($uriFormat, '{');
        $static   = $bracePos === false ? $uriFormat : substr($uriFormat, 0, $bracePos);

        return trim($static, '/');
    }

    /**
     * Compares a collection's exported url_prefix against a section's uriFormat
     * and returns true when their leading path segments diverge.
     *
     * @param string $urlPrefix  ContentiQ collection url_prefix (e.g. 'the-blog').
     * @param string $uriFormat  Craft section uriFormat (e.g. 'blog/{slug}').
     * @return bool
     */
    public static function urlPrefixDrifts(string $urlPrefix, string $uriFormat): bool
    {
        $exported = self::firstSegment($urlPrefix);
        $craft    = self::firstSegment(self::staticUriPrefix($uriFormat));

        if ($exported === '' || $craft === '') {
            return false;
        }

        return strtolower($exported) !== strtolower($craft);
    }
}
