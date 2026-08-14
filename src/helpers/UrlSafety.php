<?php

namespace matrixcreate\contentiqimporter\helpers;

/**
 * Pure, Craft-free URL safety helpers.
 *
 * Two independent concerns share this file because both are "is this URL
 * safe to act on" checks with no Craft runtime dependency:
 *   - isPublicHttpUrl() — SSRF guard for outbound image downloads
 *     (ImageImportService::_download()). Only native PHP DNS functions
 *     (gethostbyname/dns_get_record) are used, so this is unit-testable
 *     without booting Craft.
 *   - safeHref() — scheme allowlist for hrefs rendered into stored HTML
 *     (NodesRenderer, LinkHelper). Guards against javascript:/data:/
 *     vbscript: stored XSS.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.16.0
 */
class UrlSafety
{
    // Constants
    // =========================================================================

    /**
     * CIDR ranges treated as private/reserved for isPublicHttpUrl() — never a
     * valid target for a server-side outbound fetch.
     *
     * @var string[]
     */
    private const PRIVATE_RANGES = [
        '127.0.0.0/8',    // loopback
        '10.0.0.0/8',     // private
        '172.16.0.0/12',  // private
        '192.168.0.0/16', // private
        '169.254.0.0/16', // link-local (incl. cloud metadata endpoints)
        '0.0.0.0/8',      // "this network"
        '::1/128',        // loopback (IPv6)
        'fc00::/7',        // unique local (IPv6)
        'fe80::/10',       // link-local (IPv6)
    ];

    /**
     * Schemes allowed to pass through safeHref() unchanged.
     *
     * @var string[]
     */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    // Public Methods
    // =========================================================================

    /**
     * Whether $url is safe for the server to fetch: http(s) scheme, a
     * non-empty host, and — whether given as an IP literal or a hostname
     * that resolves — no address in a private/loopback/link-local/reserved
     * range (see PRIVATE_RANGES).
     *
     * Fails closed: a hostname that cannot be resolved at all is treated as
     * unsafe, since there's nothing to validate.
     *
     * @param string $url
     * @return bool
     */
    public static function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        $host = trim((string)$parts['host'], '[]');
        if ($host === '') {
            return false;
        }

        // IP literal (v4 or v6) — check directly, no DNS needed.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !self::_isPrivateIp($host);
        }

        // Hostname — resolve A + AAAA records and check every address.
        $addresses = self::_resolveHost($host);

        if (empty($addresses)) {
            return false;
        }

        foreach ($addresses as $address) {
            if (self::_isPrivateIp($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Returns $url unchanged if it's safe to render as a clickable href —
     * an allowlisted scheme (http, https, mailto, tel) or a scheme-less
     * relative/anchor/query URL — otherwise returns '#'.
     *
     * Explicitly rejects javascript:, data:, vbscript: (and any other
     * non-allowlisted scheme). Tolerant of leading/embedded whitespace and
     * control characters and of HTML-entity-obfuscated colons (e.g.
     * `java&#115;cript:`) — both are normalised away before the scheme is
     * read, so `\tjavascript:...` and `java&#115;cript:...` are caught the
     * same as a plain `javascript:` URL.
     *
     * @param string $url
     * @return string
     */
    public static function safeHref(string $url): string
    {
        $normalized = self::_normalizeForSchemeCheck($url);

        if ($normalized === '') {
            return $url;
        }

        // Relative/anchor/query URLs have no scheme — safe.
        if ($normalized[0] === '/' || $normalized[0] === '#' || $normalized[0] === '?') {
            return $url;
        }

        // No colon before the first '/' means there's no scheme at all
        // (e.g. a bare relative path like "about/team") — safe.
        $colonPos = strpos($normalized, ':');
        $slashPos = strpos($normalized, '/');

        if ($colonPos === false || ($slashPos !== false && $slashPos < $colonPos)) {
            return $url;
        }

        $scheme = strtolower(substr($normalized, 0, $colonPos));

        return in_array($scheme, self::SAFE_SCHEMES, true) ? $url : '#';
    }

    // Private Methods
    // =========================================================================

    /**
     * Decodes HTML entities and strips ASCII whitespace/control characters
     * so an obfuscated scheme is caught by the plain-text check in
     * safeHref() — mirrors how browsers ignore such characters when
     * sniffing a URL's scheme.
     *
     * @param string $url
     * @return string
     */
    private static function _normalizeForSchemeCheck(string $url): string
    {
        $decoded  = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $stripped = preg_replace('/[\x00-\x20\x7F]+/', '', $decoded);

        return $stripped ?? '';
    }

    /**
     * Resolves a hostname to its A + AAAA record addresses.
     *
     * @param string $host
     * @return string[] Empty when resolution fails entirely.
     */
    private static function _resolveHost(string $host): array
    {
        $addresses = [];

        // Suppressed: dns_get_record() can warn on some setups (e.g. no
        // resolver configured) — fail closed to an empty result instead.
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = $record['ip'];
                } elseif (!empty($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        if (empty($addresses)) {
            $resolved = @gethostbyname($host);
            // gethostbyname() returns the input unchanged when it can't resolve.
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP)) {
                $addresses[] = $resolved;
            }
        }

        return $addresses;
    }

    /**
     * Whether $ip falls inside any PRIVATE_RANGES entry.
     *
     * @param string $ip
     * @return bool
     */
    private static function _isPrivateIp(string $ip): bool
    {
        foreach (self::PRIVATE_RANGES as $range) {
            if (self::_ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether $ip is inside the given CIDR range. Handles both IPv4 and
     * IPv6 via inet_pton; mixed-family comparisons (e.g. a v4 address
     * against a v6 range) always return false.
     *
     * @param string $ip
     * @param string $cidr e.g. '10.0.0.0/8'
     * @return bool
     */
    private static function _ipInRange(string $ip, string $cidr): bool
    {
        [$rangeIp, $prefixLength] = explode('/', $cidr);

        $ipBin    = @inet_pton($ip);
        $rangeBin = @inet_pton($rangeIp);

        if ($ipBin === false || $rangeBin === false || strlen($ipBin) !== strlen($rangeBin)) {
            return false;
        }

        $prefixLength  = (int)$prefixLength;
        $fullBytes     = intdiv($prefixLength, 8);
        $remainderBits = $prefixLength % 8;

        if ($fullBytes > 0 && strncmp($ipBin, $rangeBin, $fullBytes) !== 0) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainderBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($rangeBin[$fullBytes]) & $mask);
    }
}
