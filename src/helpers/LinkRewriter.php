<?php

namespace matrixcreate\contentiqimporter\helpers;

/**
 * Upgrades root-relative links to entry references, pure PHP.
 *
 * Craft's `craft\htmlfield\HtmlField::serializeValue()` rewrites
 * `href="https://site/uri"` into a reference tag
 * (`{entry:ID@SITEID:url||original}`) so the link follows the entry when it
 * moves, but only for hrefs that start with the site's own base URL.
 * ContentiQ exports root-relative hrefs (`/`, `/parent/child`), so that
 * rewrite never fires. `rewriteHtml()` does the same job for root-relative
 * `<a href>`s in stored CKEditor HTML; `upgradeHyperLink()` is the
 * equivalent upgrade for a verbb Hyper `Url` link value → `Entry` link.
 *
 * No Craft/Hyper dependency — entry resolution is injected as a callable so
 * this runs under `tests/fixtures/craft-stubs.php` without booting Craft.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.32.0
 */
final class LinkRewriter
{
    // Constants
    // =========================================================================

    /** Reference-tag link type written for an upgraded Hyper link. */
    public const ENTRY_LINK_TYPE = 'verbb\\hyper\\links\\Entry';

    /** The Hyper link type a root-relative value is upgraded away from. */
    public const URL_LINK_TYPE = 'verbb\\hyper\\links\\Url';

    // Public Methods
    // =========================================================================

    /**
     * True only for a same-site root-relative path: exactly one leading
     * '/' (not '//host', a protocol-relative URL), and — since anything
     * else either has no leading '/' at all or is a second '/' — this one
     * check also excludes an existing reference tag ('{…'), an anchor
     * ('#…'), a scheme (mailto:, tel:, http:, https:, …), and an empty
     * string.
     *
     * @param string $href
     * @return bool
     */
    public static function isRootRelativeHref(string $href): bool
    {
        if ($href === '' || $href[0] !== '/') {
            return false;
        }

        return !isset($href[1]) || $href[1] !== '/';
    }

    /**
     * Rewrites root-relative hrefs on `<a>` tags to entry reference tags.
     *
     * Only `href=` inside an `<a …>` opening tag is ever touched — `src=`
     * (images) and every other attribute are left alone. The stored value
     * may be HTML-escaped (`&amp;` in a query string); it's treated as
     * opaque text throughout and only a copy is ever passed to
     * `parse_url()` — nothing is decoded or re-encoded.
     *
     * @param string   $html    Stored CKEditor HTML.
     * @param callable $resolve fn(string $uri): ?int — $uri is the href's
     *                          path with the leading '/' stripped ('' for
     *                          '/'); returns the entry id, or null when it
     *                          doesn't resolve. Memoised per call, so a
     *                          repeated path is only resolved once.
     * @param int      $siteId
     * @return array{html: string, resolved: int, unresolved: string[]} unresolved
     *         is the distinct paths (with leading '/') that didn't resolve,
     *         in first-seen order.
     */
    public static function rewriteHtml(string $html, callable $resolve, int $siteId): array
    {
        $resolved        = 0;
        $unresolvedPaths = [];
        $cache           = [];

        $rewritten = preg_replace_callback(
            '/<a\b[^>]*>/i',
            function (array $tagMatch) use ($resolve, $siteId, &$resolved, &$unresolvedPaths, &$cache) {
                return preg_replace_callback(
                    '/(?<=\s)href\s*=\s*(["\'])(.*?)\1/i',
                    function (array $hrefMatch) use ($resolve, $siteId, &$resolved, &$unresolvedPaths, &$cache) {
                        $quote = $hrefMatch[1];
                        $href  = $hrefMatch[2];

                        if (!self::isRootRelativeHref($href)) {
                            return $hrefMatch[0];
                        }

                        $path = parse_url($href, PHP_URL_PATH) ?? '';
                        $uri  = ltrim($path, '/');

                        if (!array_key_exists($uri, $cache)) {
                            $cache[$uri] = $resolve($uri);
                        }

                        $id = $cache[$uri];

                        if ($id === null) {
                            $unresolvedPaths[$path] = true;
                            return $hrefMatch[0];
                        }

                        // The `||` fallback text has no escape for a literal
                        // '}' — an href containing one can't be safely
                        // rewritten, so it's left untouched rather than
                        // producing a truncated reference tag.
                        if (strpos($href, '}') !== false) {
                            return $hrefMatch[0];
                        }

                        $resolved++;

                        return "href={$quote}{entry:{$id}@{$siteId}:url||{$href}}{$quote}";
                    },
                    $tagMatch[0]
                );
            },
            $html
        );

        return [
            'html'       => $rewritten,
            'resolved'   => $resolved,
            'unresolved' => array_keys($unresolvedPaths),
        ];
    }

    /**
     * Upgrades a serialized Hyper `Url` link whose value is a root-relative
     * path to an `Entry` link.
     *
     * @param array    $serialized          `Link::getSerializedValues()` shape:
     *                                       type, handle, linkValue, linkText,
     *                                       classes, newWindow, linkTitle,
     *                                       ariaLabel, urlSuffix,
     *                                       customAttributes, fields (any may
     *                                       be absent).
     * @param callable $resolve             As {@see self::rewriteHtml()}.
     * @param string   $entryLinkTypeHandle The target field's enabled Entry
     *                                       link-type handle (e.g.
     *                                       'default-verbb-hyper-links-entry').
     * @param int      $siteId
     * @return array|null New serialized Entry link, or null when not
     *                     applicable / unresolved.
     */
    public static function upgradeHyperLink(array $serialized, callable $resolve, string $entryLinkTypeHandle, int $siteId): ?array
    {
        $path = self::_candidatePath($serialized);

        if ($path === null) {
            return null;
        }

        $id = $resolve(ltrim($path, '/'));

        if ($id === null) {
            return null;
        }

        $entryLink = [
            'type'       => self::ENTRY_LINK_TYPE,
            'handle'     => $entryLinkTypeHandle,
            'linkValue'  => [$id],
            'linkSiteId' => $siteId,
        ];

        // Every other key carries over untouched, in its original order —
        // including the legacy `linkClass` key the plugin currently writes.
        foreach ($serialized as $key => $value) {
            if ($key === 'type' || $key === 'handle' || $key === 'linkValue' || $key === 'linkSiteId') {
                continue;
            }

            $entryLink[$key] = $value;
        }

        return $entryLink;
    }

    /**
     * Whether a serialized Hyper link is a root-relative-path candidate —
     * a `Url` link with no query/fragment — before it's resolved. Used by
     * callers that want to raise an "unresolved" warning without
     * duplicating {@see self::upgradeHyperLink()}'s own candidacy check.
     *
     * @param array $serialized
     * @return string|null The root-relative path, or null.
     */
    public static function hyperLinkPath(array $serialized): ?string
    {
        return self::_candidatePath($serialized);
    }

    // Private Methods
    // =========================================================================

    /**
     * Shared candidacy check behind {@see self::upgradeHyperLink()} and
     * {@see self::hyperLinkPath()}: a `Url`-typed link whose value is a
     * root-relative path with no query string or fragment.
     *
     * @param array $serialized
     * @return string|null
     */
    private static function _candidatePath(array $serialized): ?string
    {
        if (($serialized['type'] ?? '') !== self::URL_LINK_TYPE) {
            return null;
        }

        $value = $serialized['linkValue'] ?? null;

        if (is_array($value)) {
            $value = self::_firstScalar($value);
        }

        if (!is_string($value) || !self::isRootRelativeHref($value)) {
            return null;
        }

        if (strpos($value, '?') !== false || strpos($value, '#') !== false) {
            return null;
        }

        return $value;
    }

    /**
     * The first scalar element of $value, or null when it has none.
     *
     * @param array $value
     * @return mixed
     */
    private static function _firstScalar(array $value): mixed
    {
        foreach ($value as $item) {
            if (is_scalar($item)) {
                return $item;
            }
        }

        return null;
    }
}
