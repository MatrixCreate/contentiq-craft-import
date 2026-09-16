<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\elements\Entry;
use Throwable;
use yii\base\Component;

/**
 * PASS 4: Creates Retour static redirects from `document.legacy_url` for
 * every page genuinely written this run — the old-site URL now 301s straight
 * to the page's live Craft URI instead of 404ing once the old site is
 * retired.
 *
 * Runs at the same point in the pipeline as PASS 2/PASS 3 (card references,
 * link sweep) — see {@see ImportService::runPostPasses()}, the single entry
 * point every caller uses. Scope rule is identical to the link sweep: only
 * the elements genuinely written THIS run (`ImportService::_writtenPages()`)
 * are ever considered — never a locked/skipped/deselected page.
 *
 * `nystudio107/craft-retour` is never a composer dependency of this plugin
 * (see AGENTS.md) — every reference to its classes below is guarded by
 * {@see _retourAvailable()} first, so a site without Retour installed never
 * fatals; it just gets one run-level warning (see `sweep()`'s docblock) and
 * every other pipeline step continues unaffected.
 *
 * This pass never deletes an existing Retour redirect. `saveRedirect()` is
 * always called with `checkForRedirectLoop: false` — Retour's own loop
 * prevention (`checkForRedirectLoop: true`) silently DELETES any existing
 * redirect whose `redirectSrcUrl` equals our `redirectDestUrl`, unscoped to
 * who created it; a hand-made CP redirect `/promotions -> /new-offers` would
 * vanish the moment this pass creates `/promos -> /promotions`. Instead,
 * {@see _existingRedirectDestination()} checks read-only whether our
 * destination already has an outbound redirect of its own and, if so, warns
 * that the two will chain rather than silently deleting either one.
 *
 * `saveRedirect()`'s config array never sets `associatedElementId` — it's
 * left at the model's own default (0) deliberately. Retour treats a non-zero
 * `associatedElementId` as a "Short Link" and hides it from the CP Redirects
 * list (`TablesController::actionRedirectTableData()` filters
 * `associatedElementId = 0`); setting it to the owner entry's id would make
 * every redirect this pass creates invisible in Retour's own UI.
 *
 * `sweep()` also guards against reading `$entry->uri` before it's been
 * written: {@see \matrixcreate\contentiqimporter\services\ImportService::refreshUri()}
 * is normally called by `SyncJob`/`CpController` right after structure
 * positioning, but as a belt-and-braces check `sweep()` retries the same
 * inline `updateElementSlugAndUri()` call once itself before skipping a page
 * with an empty URI — see "URI-timing trap" in docs/import-pipeline.md.
 *
 * Every page gets TWO Retour rows per redirect, not one — see
 * {@see sourceVariants()}. Retour exact-matches `redirectSrcUrl` against the
 * raw request path with no slash normalisation of its own, and Craft does
 * not canonicalise a slash-less inbound request before Retour's 404 handler
 * sees it, so both `/old-kitchens/` and `/old-kitchens` are saved as separate
 * rows. The destination (`redirectDestUrl`) stays a single trailing-slash
 * form either way — it's Craft's own canonical URL, not raw user input.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.33.0
 */
class RedirectService extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * PASS 4: Creates a Retour static redirect from each written page's
     * `legacy_url` to its live Craft URI. Returns warnings keyed by owner
     * page entry id — the same `array<int, string[]>` shape
     * {@see LinkSweepService::sweep()} returns, so `runPostPasses()` merges
     * both into one map with no special-casing.
     *
     * Guards, in order:
     *   1. `redirects.enabled` config (default true) — off skips the whole
     *      pass silently.
     *   2. `$written` filtered down to entries that actually carry a
     *      non-empty `legacyUrl` — a page a scraper never captured a legacy
     *      URL for has nothing to redirect from, and isn't worth warning
     *      about.
     *   3. Retour installed AND enabled
     *      (`Craft::$app->getPlugins()->isPluginEnabled('retour')` +
     *      `class_exists(\nystudio107\retour\Retour::class)`) — checked only
     *      once step 2 has established there's at least one page that would
     *      otherwise get a redirect, so a project with no `legacy_url` data
     *      at all never sees a "Retour not installed" warning it has no use
     *      for. When Retour is missing, ONE warning is attached to the first
     *      such page (not one per page — see the class docblock) and the
     *      pass stops there.
     *   4. Per page — source path (`legacyUrlToPath()`) must parse; a page
     *      whose current source path already matches its Craft destination
     *      is skipped silently, ALL source variants included (the common
     *      case: scrapers fill `legacy_url` from the page's own current URL).
     *
     * Never deletes an existing Retour redirect — see the class docblock.
     * Writes TWO source rows per page (trailing-slash and slash-less) — see
     * {@see sourceVariants()} and the class docblock.
     *
     * @param array<int, ?string> $written Owner entry id => `legacyUrl` (or null),
     *                                     already filtered to elements genuinely
     *                                     written this run by
     *                                     `ImportService::_writtenPages()`.
     * @param bool $dryRun If true, resolve and warn but create nothing —
     *                     warns "Would create redirect: …" instead of saving.
     * @return array<int, string[]> Warnings keyed by owner entry ID.
     */
    public function sweep(array $written, bool $dryRun = false): array
    {
        $config = $this->_config();

        if (!($config['enabled'] ?? true)) {
            return [];
        }

        $candidates = array_filter(
            $written,
            static fn(mixed $legacyUrl): bool => is_string($legacyUrl) && trim($legacyUrl) !== '',
        );

        if (empty($candidates)) {
            return [];
        }

        if (!$this->_retourAvailable()) {
            $firstEntryId = array_key_first($candidates);

            return [$firstEntryId => ['Retour not installed — legacy URL redirects skipped.']];
        }

        $httpCode        = (int)($config['httpCode'] ?? 301);
        $warningsByOwner = [];

        foreach ($candidates as $entryId => $legacyUrl) {
            $warn = function (string $message) use (&$warningsByOwner, $entryId): void {
                $warningsByOwner[$entryId][] = $message;
            };

            $sourcePath = self::legacyUrlToPath($legacyUrl);

            if ($sourcePath === null) {
                $warn("Legacy URL '{$legacyUrl}' could not be parsed — redirect skipped.");
                continue;
            }

            try {
                $entry = Entry::find()->id($entryId)->status(null)->one();
            } catch (Throwable $e) {
                $warn('Could not load entry to create a redirect: ' . $e->getMessage());
                continue;
            }

            if ($entry === null) {
                continue;
            }

            // Structure positioning (SyncJob/CpController) queues its own URI write via
            // afterMoveInStructure() rather than performing it inline, and this pass runs
            // in the same run — normally ImportService::refreshUri() already forced it, but
            // belt-and-braces here: a null/empty uri (homepage is fine as '__home__') gets
            // one inline refresh attempt before we give up, so a same-run race never writes
            // '/' as every page's redirect destination.
            if (($entry->uri === null || $entry->uri === '') && $entry->uri !== '__home__') {
                try {
                    Craft::$app->getElements()->updateElementSlugAndUri($entry, true, false, false);
                } catch (Throwable) {
                    // Swallowed — the empty-uri check below still catches it and skips
                    // this page's redirect with a warning rather than building one from '/'.
                }

                if ($entry->uri === null || $entry->uri === '') {
                    $warn("Entry {$entry->id} has no URI yet — legacy redirect skipped.");
                    continue;
                }
            }

            $destPath = self::entryUriToPath((string)$entry->uri);

            // The common case — scrapers fill legacy_url from the page's own
            // current URL, so most pages need no redirect at all.
            if ($sourcePath === $destPath) {
                continue;
            }

            if (self::legacyUrlHadQuery($legacyUrl)) {
                $warn("Legacy URL '{$legacyUrl}' had a query string — ignored when creating the redirect.");
            }

            // Advisory only — read-only, never deletes anything (see the
            // class docblock for why checkForRedirectLoop is off below).
            // Once per page, not per source variant below — it's about the
            // shared destination, not the source path.
            $chainedDest = $this->_existingRedirectDestination($destPath, $entry->siteId);

            if ($chainedDest !== null) {
                $warn("Retour already redirects {$destPath} \u{2192} {$chainedDest}; legacy redirect {$sourcePath} \u{2192} {$destPath} will chain — review in Retour.");
            }

            // Retour matches redirectSrcUrl against the raw request path literally,
            // and Craft does not canonicalise an inbound slash-less request before
            // it reaches Retour's 404 handler — so both slash forms of the source
            // are written as separate exact-match rows. See sourceVariants().
            $sourceVariants = self::sourceVariants($sourcePath);

            if ($dryRun) {
                foreach ($sourceVariants as $variant) {
                    $warn("Would create redirect: {$variant} \u{2192} {$destPath}");
                }
                continue;
            }

            foreach ($sourceVariants as $variant) {
                try {
                    // checkForRedirectLoop is deliberately false — Retour's own
                    // loop prevention silently DELETES any existing redirect
                    // whose redirectSrcUrl equals our redirectDestUrl, unscoped
                    // to ownership. See the class docblock.
                    // associatedElementId is deliberately omitted — it defaults to 0 on
                    // the model, and Retour's own Redirects-list query
                    // (TablesController::actionRedirectTableData()) filters on
                    // associatedElementId = 0 to hide "Short Links"; a non-zero value here
                    // would make our redirect invisible in the CP Redirects list. See the
                    // class docblock.
                    $saved = \nystudio107\retour\Retour::$plugin->redirects->saveRedirect([
                        'id'                => 0,
                        'siteId'            => $entry->siteId,
                        'enabled'           => true,
                        'redirectSrcUrl'    => $variant,
                        'redirectSrcMatch'  => 'pathonly',
                        'redirectMatchType' => 'exactmatch',
                        'redirectDestUrl'   => $destPath,
                        'redirectHttpCode'  => $httpCode,
                    ], checkForRedirectLoop: false);
                } catch (Throwable $e) {
                    $warn("Could not create redirect from '{$variant}': " . $e->getMessage());
                    continue;
                }

                if (!$saved) {
                    $warn("Could not create redirect from '{$variant}' — Retour rejected it.");
                }
            }
        }

        return $warningsByOwner;
    }

    /**
     * Normalises a legacy URL (absolute, old-site domain) down to a
     * root-relative path Retour can exact-match against: scheme, host,
     * query string, and fragment are all dropped; the result carries a
     * leading `/`, and — except for the two cases below — a trailing `/`
     * too, matching this plugin's canonical URL form.
     *
     * Ben's ruling: every Craft site this plugin targets runs
     * `addTrailingSlashesToUrls: true`, and Retour matches
     * `redirectSrcUrl`/`redirectSrcMatch: 'exactmatch'` against the raw
     * request URI literally, with no slash normalisation of its own
     * (confirmed by reading `handle404()`/`getStaticRedirect()` in
     * `nystudio107\retour\services\Redirects` directly) — so a redirect
     * built without the trailing slash would simply never match a real
     * request and silently 404 instead of firing.
     *
     * Two exceptions:
     *   - The root path is always just `/`, never `//`.
     *   - A "file-like" last path segment — one containing a `.`, e.g.
     *     `/roofing.html`, `/index.php` — never gets a trailing slash
     *     appended. A request for `roofing.html/` never happens; the old
     *     site's own URL is already the canonical (slash-free) form for a
     *     literal file, and appending one would just make the redirect
     *     never match either.
     *
     * Pure — no Craft dependency — so it's unit-testable standalone (see
     * `tests/run-transforms.php`).
     *
     * @param string $url
     * @return ?string The normalised path, or null when $url is empty or
     *                 not parseable by `parse_url()`.
     */
    public static function legacyUrlToPath(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if ($path === '') {
            return '/';
        }

        // Leading slash guaranteed, any existing slashes (leading or
        // trailing, single or repeated) collapsed first so the trailing-
        // slash rule below is applied to one canonical shape rather than
        // layered on top of whatever the source URL happened to already have.
        $path = '/' . trim($path, '/');

        if ($path === '/') {
            return $path;
        }

        $lastSegment = substr($path, strrpos($path, '/') + 1);

        if (str_contains($lastSegment, '.')) {
            return $path;
        }

        return $path . '/';
    }

    /**
     * Expands a {@see legacyUrlToPath()} canonical (trailing-slash) source path
     * into the distinct `redirectSrcUrl` values `sweep()` actually saves.
     *
     * Ben's ruling: Retour matches `redirectSrcUrl` against the raw request
     * path literally (see {@see legacyUrlToPath()}'s docblock), and Craft does
     * NOT canonicalise an inbound `/old-kitchens` request to `/old-kitchens/`
     * before routing reaches Retour's 404 handler — so a trailing-slash-only
     * redirect silently misses any inbound link/bookmark that omits the
     * slash. Both forms are written as separate exact-match rows to cover
     * either.
     *
     * Three shapes, slash form always first:
     *   - Root (`/`) — one form only; there's no slash-less variant of the root.
     *   - File-like (`/roofing.html`, no trailing slash by construction — see
     *     {@see legacyUrlToPath()}) — one form only; appending a slash would
     *     produce a path a real request never sends.
     *   - Everything else — both `{path}/` and `{path}` (trailing slash
     *     stripped).
     *
     * Pure — no Craft dependency — so it's unit-testable standalone (see
     * `tests/run-transforms.php`).
     *
     * @param string $canonicalPath The trailing-slash canonical form returned
     *                               by {@see legacyUrlToPath()}.
     * @return string[] Distinct `redirectSrcUrl` values to save, slash form first.
     */
    public static function sourceVariants(string $canonicalPath): array
    {
        if ($canonicalPath === '/') {
            return ['/'];
        }

        if (!str_ends_with($canonicalPath, '/')) {
            return [$canonicalPath];
        }

        return [$canonicalPath, rtrim($canonicalPath, '/')];
    }

    /**
     * True when `$url` carries a non-empty query string — used to decide
     * whether to warn that it was dropped from the redirect's source path.
     * Pure — see {@see legacyUrlToPath()}.
     *
     * @param string $url
     * @return bool
     */
    public static function legacyUrlHadQuery(string $url): bool
    {
        $parts = parse_url(trim($url));

        return $parts !== false && !empty($parts['query']);
    }

    /**
     * Converts a Craft entry's native `uri` into this plugin's canonical
     * redirect-destination path: leading AND trailing `/`, `/` for the
     * homepage's `__home__` (or an empty uri, the same "no URL format"
     * case `_refreshUri()` leaves as null elsewhere in this plugin).
     *
     * No file-extension exception here, unlike {@see legacyUrlToPath()} —
     * a Craft entry's `uri` is never file-like, so every non-homepage
     * destination always gets the trailing slash the target site's
     * `addTrailingSlashesToUrls: true` requires (see that method's
     * docblock for the full rationale).
     *
     * Pure — no Craft dependency — so it's unit-testable standalone (see
     * `tests/run-transforms.php`).
     *
     * @param string $uri
     * @return string
     */
    public static function entryUriToPath(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '' || $uri === '__home__') {
            return '/';
        }

        return '/' . trim($uri, '/') . '/';
    }

    // Private Methods
    // =========================================================================

    /**
     * Loads the `redirects` sub-array of the contentiq config, defaults applied.
     * Reads `config/contentiq.php` directly (mirrors
     * `ImportService::resolveCardReferences()`'s own direct read) rather than
     * going through `ImportService::_getConfig()`, which is private to that
     * class.
     *
     * @return array{enabled: bool, httpCode: int}
     */
    private function _config(): array
    {
        $defaults = ['enabled' => true, 'httpCode' => 301];

        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $override      = (is_array($projectConfig) && is_array($projectConfig['redirects'] ?? null))
            ? $projectConfig['redirects']
            : [];

        return array_replace($defaults, $override);
    }

    /**
     * Read-only chain check: does Retour already have a redirect FROM
     * `$path` (our about-to-be-created redirect's destination)? Used to warn
     * that the new redirect will chain through an existing one, never to
     * delete or alter it — see the class docblock for why this replaces
     * `saveRedirect()`'s own destructive `checkForRedirectLoop: true` path.
     *
     * `Retour::$plugin->redirects->getRedirectByRedirectSrcUrl()` is public
     * (confirmed by reading `nystudio107\retour\services\Redirects` directly)
     * — a plain `{{%retour_static_redirects}}` query is the fallback only a
     * private method would have required. Any failure here (a Retour
     * version whose signature changed, a DB hiccup) is swallowed — this is
     * advisory only and must never block creating our own redirect.
     *
     * @param string   $path   The path to check for an existing outbound redirect.
     * @param int|null $siteId
     * @return ?string The existing redirect's destination, or null when none exists
     *                 (or the check itself failed).
     */
    private function _existingRedirectDestination(string $path, ?int $siteId): ?string
    {
        try {
            $existing = \nystudio107\retour\Retour::$plugin->redirects->getRedirectByRedirectSrcUrl($path, $siteId);
        } catch (Throwable $e) {
            return null;
        }

        if (!is_array($existing) || empty($existing['redirectDestUrl'])) {
            return null;
        }

        return (string)$existing['redirectDestUrl'];
    }

    /**
     * True when `nystudio107/craft-retour` is both installed+enabled and its
     * classes are actually autoloadable. Never a composer dependency of this
     * plugin (see AGENTS.md) — `class_exists()` is safe to call whether or
     * not it's present.
     *
     * @return bool
     */
    private function _retourAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('retour')
            && class_exists(\nystudio107\retour\Retour::class);
    }
}
