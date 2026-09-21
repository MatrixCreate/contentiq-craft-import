<?php

namespace matrixcreate\contentiqimporter\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\models\Section;
use craft\web\Controller;
use craft\web\UploadedFile;
use craft\helpers\StringHelper;
use craft\helpers\Queue as QueueHelper;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\helpers\SyncQueueConfig;
use matrixcreate\contentiqimporter\helpers\TempFileSafety;
use matrixcreate\contentiqimporter\jobs\FinaliseRunJob;
use matrixcreate\contentiqimporter\jobs\ImportPagesJob;
use matrixcreate\contentiqimporter\jobs\PostPassJob;
use matrixcreate\contentiqimporter\jobs\StageRunJob;
use matrixcreate\contentiqimporter\models\SyncRun;
use matrixcreate\contentiqimporter\services\SyncRunService;
use Psr\Http\Message\ResponseInterface;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * CP controller for the ContentIQ dashboard, upload, preview, and result screens.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.1.0
 */
class CpController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Intro screen — sync and import entry points.
     *
     * @return Response
     */
    public function actionIndex(): Response
    {
        $settings = ContentIQImporter::$plugin->getSettings();
        $apiConfigured = $settings->contentiqUrl !== ''
            && $settings->apiKey !== '';

        return $this->renderTemplate('contentiq-importer/_cp/index', [
            'apiConfigured' => $apiConfigured,
        ]);
    }

    /**
     * Import history — lists previous import runs.
     *
     * @return Response
     */
    public function actionHistory(): Response
    {
        $runs = (new Query())
            ->select(['id', 'importedBy', 'filename', 'type', 'source', 'phase', 'pageCount', 'imageCount', 'status', 'dateCreated'])
            ->from('{{%contentiq_import_runs}}')
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(50)
            ->all();

        return $this->renderTemplate('contentiq-importer/_cp/history', [
            'runs' => $runs,
        ]);
    }

    /**
     * Mappings screen — maps ContentIQ collection slugs to Craft routing.
     *
     * Renders one row per project collection (from the wire) plus any slug still
     * held in settings but no longer exported (badged "not in ContentiQ"). Rows
     * whose slug is defined in config/contentiq.php `content_types` render
     * read-only — the file wins. On API failure the page still renders from the
     * stored settings mappings with an error banner.
     *
     * @return Response
     */
    public function actionMappings(): Response
    {
        // Mappings are project config — admin-only, like Craft's own plugin
        // settings screens (viewing is allowed even when admin changes are off).
        $this->requireAdmin(false);

        $plugin         = ContentIQImporter::$plugin;
        $settings       = $plugin->getSettings();
        $storedMappings = $settings->collectionMappings;

        // Fetch collections from the wire. On failure fall back to stored slugs.
        $apiError    = null;
        $collections = [];

        if ($settings->contentiqUrl === '' || $settings->apiKey === '') {
            $apiError = Craft::t('contentiq-importer', 'ContentiQ API is not configured. Set the URL and API key in plugin settings.');
        } else {
            $response = $plugin->api->fetchGlobals();

            if ($response['success']) {
                $collections = $response['data']['globals']['collections'] ?? [];
            } else {
                $apiError = $response['error'] ?? Craft::t('contentiq-importer', 'Could not reach ContentiQ.');
            }
        }

        // Index wire collections by slug.
        $wireBySlug = [];

        foreach ($collections as $collection) {
            $slug = (string)($collection['slug'] ?? '');

            if ($slug === '') {
                continue;
            }

            $wireBySlug[$slug] = $collection;
        }

        // Raw config-file content_types — these slugs render read-only (file wins).
        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $fileSlugs     = (is_array($projectConfig) && is_array($projectConfig['content_types'] ?? null))
            ? $projectConfig['content_types']
            : [];

        // Effective merged map (defaults ← settings ← file) drives pre-selection.
        $effectiveMap = $plugin->imports->getContentTypesMap();

        // Row set = wire collection slugs ∪ stored settings slugs.
        $slugs = array_values(array_unique(array_merge(
            array_keys($wireBySlug),
            array_keys($storedMappings),
        )));
        sort($slugs);

        $rows = [];

        foreach ($slugs as $slug) {
            $rows[] = [
                'slug'           => $slug,
                'urlPrefix'      => $wireBySlug[$slug]['url_prefix'] ?? null,
                'notInContentiq' => !isset($wireBySlug[$slug]),
                'inFile'         => isset($fileSlugs[$slug]),
                'mapping'        => $effectiveMap[$slug] ?? null,
            ];
        }

        return $this->renderTemplate('contentiq-importer/_cp/mappings', [
            'rows'         => $rows,
            'sectionsData' => $this->_buildSectionsData(),
            'apiError'     => $apiError,
        ]);
    }

    /**
     * Persists the collection mappings edited on the Mappings screen.
     *
     * Read-only (config-file) rows carry no inputs, so they never post and never
     * land in settings — the file stays the escape hatch. Rows with an empty
     * section are dropped here and again by the Settings model's validation.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionSaveMappings(): Response
    {
        $this->requirePostRequest();
        // Writes project config — admin + allowAdminChanges, like Craft's own
        // plugin-settings saves (403 instead of a ProjectConfig 500 on prod).
        $this->requireAdmin();

        $posted = Craft::$app->getRequest()->getBodyParam('mappings');

        // A missing/malformed param means the form rendered no editable rows (or
        // a broken client) — treat as a no-op rather than deleting every row.
        if (!is_array($posted)) {
            $posted = [];
        }

        $str = static fn(mixed $v): string => is_scalar($v) ? trim((string)$v) : '';

        $mappings   = [];
        $incomplete = [];

        foreach ($posted as $slug => $row) {
            if (!is_array($row)) {
                continue;
            }

            $section = $str($row['section'] ?? '');

            if ($section === '') {
                continue;
            }

            $entryType    = $str($row['entryType'] ?? '');
            $contentField = $str($row['contentField'] ?? '');

            // A mapped collection needs all three — a partial row would fatal
            // per-page at import time ("Entry type '' not found").
            if ($entryType === '' || $contentField === '') {
                $incomplete[] = (string)$slug;
                continue;
            }

            $headingField = $str($row['headingField'] ?? '');
            $blocksField  = $str($row['blocksField'] ?? '');

            // blocksField is optional — unlike entryType/contentField above, a
            // row with no blocks field configured is complete and valid; it
            // just falls back to config['matrixField'] at import time.
            $mappings[(string)$slug] = [
                'section'      => $section,
                'entryType'    => $entryType,
                'contentField' => $contentField,
                'headingField' => $headingField !== '' ? $headingField : null,
                'blocksField'  => $blocksField !== '' ? $blocksField : null,
            ];
        }

        // Rows shadowed by a config-file override render read-only (no inputs),
        // so they never post — re-preserve their stored values instead of
        // silently deleting them on every unrelated save.
        $projectConfig = Craft::$app->getConfig()->getConfigFromFile('contentiq');
        $fileSlugs     = (is_array($projectConfig) && is_array($projectConfig['content_types'] ?? null))
            ? $projectConfig['content_types']
            : [];

        foreach (ContentIQImporter::$plugin->getSettings()->collectionMappings as $slug => $row) {
            if (isset($fileSlugs[$slug]) && !isset($mappings[$slug])) {
                $mappings[$slug] = $row;
            }
        }

        // savePluginSettings() replaces the whole project-config settings node
        // with only the keys passed here (toArray(array_keys($settings))), so a
        // partial array would wipe contentiqUrl/apiKey. Always pass every key.
        $settings = ContentIQImporter::$plugin->getSettings();

        $saved = Craft::$app->getPlugins()->savePluginSettings(ContentIQImporter::$plugin, [
            'contentiqUrl'       => $settings->contentiqUrl,
            'apiKey'             => $settings->apiKey,
            'collectionMappings' => $mappings,
        ]);

        if (!$saved) {
            Craft::$app->getSession()->setError(Craft::t('contentiq-importer', 'Couldn’t save mappings.'));

            return $this->redirect('contentiq-importer/mappings');
        }

        if (!empty($incomplete)) {
            Craft::$app->getSession()->setNotice(Craft::t('contentiq-importer', 'Mappings saved. Skipped incomplete rows (need entry type + content field): {slugs}', [
                'slugs' => implode(', ', $incomplete),
            ]));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('contentiq-importer', 'Mappings saved.'));
        }

        return $this->redirect('contentiq-importer/mappings');
    }

    /**
     * Upload screen — file picker and drag-and-drop.
     *
     * @return Response
     */
    public function actionUpload(): Response
    {
        $this->requirePermission('contentiq-importer:sync');

        return $this->renderTemplate('contentiq-importer/_cp/upload');
    }

    /**
     * Preview — receives uploaded JSON, validates, runs dry-run, shows what will happen.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('contentiq-importer:sync');

        $uploadedFile = UploadedFile::getInstanceByName('jsonFile');

        if ($uploadedFile === null) {
            Craft::$app->getSession()->setError('No file was uploaded.');

            return $this->redirect('contentiq-importer/upload');
        }

        $json = file_get_contents($uploadedFile->tempName);

        if ($json === false) {
            Craft::$app->getSession()->setError('Could not read the uploaded file.');

            return $this->redirect('contentiq-importer/upload');
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Craft::$app->getSession()->setError('Invalid JSON: ' . json_last_error_msg());

            return $this->redirect('contentiq-importer/upload');
        }

        // Detect single vs batch.
        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = $isBatch ? $data['pages'] : [$data];

        // Prepare services for dry-run.
        $importService = ContentIQImporter::$plugin->imports;

        // Resets per-run state (currently just the "first global CTA block
        // wins" tracking — see ImportService::beginRun()) so this preview
        // never inherits state from a previous run in the same PHP process.
        $importService->beginRun();

        $previewResults = [];
        $totalWarnings  = 0;

        foreach ($pages as $pageData) {
            $result = $importService->importPage($pageData, dryRun: true);

            // Check if entry exists.
            $slug     = $result['slug'] ?? '';
            $existing = Entry::find()->section('pages')->slug(Db::escapeParam((string)$slug))->status(null)->one();

            $result['willCreate'] = $existing === null;
            $result['existingId'] = $existing?->id;

            $previewResults[] = $result;

            if (!empty($result['warnings'])) {
                $totalWarnings += count($result['warnings']);
            }
        }

        // Count creates vs updates.
        $createCount = count(array_filter($previewResults, fn($r) => $r['willCreate']));
        $updateCount = count($previewResults) - $createCount;

        // Dry-run the globals payload when present so the preview can summarise it.
        // The service's dryRun path writes nothing (offices, global sets, and image
        // imports are all gated on !$dryRun).
        $globalsPreview = null;

        if (isset($data['globals']) && is_array($data['globals'])) {
            $globalsPreview          = ContentIQImporter::$plugin->globals->import($data['globals'], dryRun: true);
            $globalsPreview['drift'] = ContentIQImporter::$plugin->globals
                ->checkUrlPrefixDrift($data['globals']['collections'] ?? []);
        }

        // Store JSON in session for the import step.
        $tempFilename = 'contentiq-import-' . gmdate('ymd_His') . '.json';
        $tempPath     = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $tempFilename;
        file_put_contents($tempPath, $json);

        return $this->renderTemplate('contentiq-importer/_cp/preview', [
            'pages'          => $previewResults,
            'isBatch'        => $isBatch,
            'pageCount'      => count($previewResults),
            'createCount'    => $createCount,
            'updateCount'    => $updateCount,
            'totalWarnings'  => $totalWarnings,
            'tempFilename'   => $tempFilename,
            'exportDate'     => $data['exported_at'] ?? null,
            'globalsPreview' => $globalsPreview,
        ]);
    }

    /**
     * Saves the uploaded export to runtime storage, stages a run for it on
     * the same batched pipeline the Sync screen uses, and redirects to the
     * sync status screen (§3.7). Replaces the upload path's own inline
     * import loop/post-pass call/`_saveRun()` — StageRunJob does the
     * envelope validation, ImportPagesJob the per-page import + Structure
     * positioning, PostPassJob the card-ref/link-sweep passes, and
     * FinaliseRunJob the report — `source='upload'` skips the `ack` step
     * (there's no ContentiQ-side pending state to retire for a file that
     * didn't come from the API) and globals are never touched (the upload
     * path has no lock/consent UI — same as before this change).
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionRunImport(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('contentiq-importer:sync');

        // Sanitize before building a filesystem path — the raw POST value is
        // untrusted and a '../' would otherwise allow arbitrary file read/delete
        // via getTempPath() below.
        $tempFilename = TempFileSafety::sanitize(
            (string)Craft::$app->getRequest()->getRequiredBodyParam('tempFilename'),
        );

        if ($tempFilename === null) {
            Craft::$app->getSession()->setError('Import file expired. Please upload again.');

            return $this->redirect('contentiq-importer/upload');
        }

        $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $tempFilename;

        if (!is_file($tempPath)) {
            Craft::$app->getSession()->setError('Import file expired. Please upload again.');

            return $this->redirect('contentiq-importer/upload');
        }

        // StageRunJob reads the payload back off disk itself (it may run in
        // a separate process/request from this one) — copy the preview's
        // temp file into the runtime path it expects instead of decoding it
        // here. `is_file()` above already confirmed it's readable.
        $runtimeDir = Craft::$app->getPath()->getRuntimePath() . DIRECTORY_SEPARATOR . 'contentiq-importer' . DIRECTORY_SEPARATOR . 'runs';
        FileHelper::createDirectory($runtimeDir);

        $service = ContentIQImporter::$plugin->syncRuns;

        // The runtime file is named after the run id, which only exists
        // once createRun() returns — insert the run first with `file: null`,
        // then patch `options.file` once the real path is known. `dryRun` is
        // always false here — actionPreview() is the dry-run path and stays
        // synchronous (§3.7's explicit phase-1 carve-out).
        $run = $service->createRun('upload', [
            'unlockIds'     => null,
            'unlockGlobals' => false,
            'newSelections' => [],
            'file'          => null,
            'dryRun'        => false,
        ], Craft::$app->getUser()->getId(), basename($tempFilename));

        $runtimeFile = $runtimeDir . DIRECTORY_SEPARATOR . $run->id . '.json';

        if (!copy($tempPath, $runtimeFile)) {
            $service->fail($run->id, 'Could not stage the uploaded file for import.');

            Craft::$app->getSession()->setError('Could not stage the uploaded file for import.');

            return $this->redirect('contentiq-importer/upload');
        }

        Craft::$app->getDb()->createCommand()->update(
            '{{%contentiq_import_runs}}',
            ['options' => Json::encode(['unlockIds' => null, 'unlockGlobals' => false, 'newSelections' => [], 'file' => $runtimeFile, 'dryRun' => false])],
            ['id' => $run->id],
        )->execute();

        $jobId = QueueHelper::push(new StageRunJob(['runId' => $run->id]), null, 0, SyncQueueConfig::ttr());
        $service->setQueueJobId($run->id, $jobId !== null ? (int)$jobId : null);

        // Clean up the preview's own temp copy — StageRunJob now reads from
        // the runtime copy above.
        @unlink($tempPath);

        // Same status/polling screen actionRunSync()'s own client-side flow
        // lands on (contentiq-importer/sync, see sync.twig) — `runId`/
        // `source` tell it to skip the idle tree-selection view and start
        // polling this already-queued run immediately, labelled "Upload"
        // instead of "Sync" (§3.7).
        return $this->redirect('contentiq-importer/sync?runId=' . $run->id . '&source=upload');
    }

    /**
     * Result screen — shows the outcome of a completed import run.
     *
     * Sync runs are redirected to the purpose-built sync-result screen (Fix
     * B-result): since the 1.22.0 sync overhaul, SyncJob stores its result as
     * a shape-tagged object (`{pages, globals, ackWarning}`, see SyncJob.php),
     * not the flat per-page array every other run type still stores. This
     * legacy template iterates `run.result` directly and was never updated
     * for that shape, so a sync run rendered here shows three garbage rows
     * (one per top-level key) instead of the actual pages.
     *
     * @param int $runId
     * @return Response
     */
    public function actionResult(int $runId): Response
    {
        $run = (new Query())
            ->from('{{%contentiq_import_runs}}')
            ->where(['id' => $runId])
            ->one();

        if ($run === null) {
            throw new \yii\web\NotFoundHttpException('Import run not found.');
        }

        if ($run['type'] === 'sync') {
            return $this->redirect('contentiq-importer/sync/result/' . $runId);
        }

        $run['result'] = Json::decodeIfJson($run['result'] ?? '[]');

        return $this->renderTemplate('contentiq-importer/_cp/result', [
            'run' => $run,
        ]);
    }

    /**
     * Sync screen — shows API details and sync button.
     *
     * On load, shows the FULL ContentiQ project tree (everything in the
     * project, not just what's already been imported) merged with local sync
     * state, matched via ImportService::findExistingEntry() (id-first, same
     * resolver the actual import/lock-check pipeline uses) — see
     * _buildFullSyncTree(). Falls back to the local-only view
     * (_buildLocalOnlySyncTree() — what this screen showed before this
     * change) whenever the API is unconfigured or unreachable, with a
     * dismissible warning banner. The screen must never 500 because
     * ContentiQ is down.
     *
     * @return Response
     */
    public function actionSync(): Response
    {
        $settings      = ContentIQImporter::$plugin->getSettings();
        $apiConfigured = $settings->contentiqUrl !== '' && $settings->apiKey !== '';

        $config        = Craft::$app->config->getConfigFromFile('contentiq');
        $sectionHandle = $config['section'] ?? 'pages';

        // Ordered list of collection sections from content_types config.
        // Pages comes first, then each distinct collection section in config order.
        $contentTypesMap    = ContentIQImporter::$plugin->imports->getContentTypesMap();
        $collectionSections = [];

        foreach ($contentTypesMap as $route) {
            $handle = $route['section'] ?? null;

            if ($handle !== null
                && $handle !== $sectionHandle
                && !in_array($handle, $collectionSections, true)) {
                $collectionSections[] = $handle;
            }
        }

        $apiWarning     = null;
        $syncGroups     = [];
        $hasSyncRecords = false;

        if ($apiConfigured) {
            // Shorter timeouts than the queue job's fetchExport() call — this
            // runs synchronously on page load, so a slow/hung ContentiQ
            // instance shouldn't hang the CP request for the job's full 120s.
            $apiResult = ContentIQImporter::$plugin->api->fetchExport(timeout: 20, connectTimeout: 5);

            if ($apiResult['success'] && is_array($apiResult['data'])) {
                $syncGroups     = $this->_buildFullSyncTree($apiResult['data'], $sectionHandle, $collectionSections);
                $hasSyncRecords = count($syncGroups) > 0;
            } else {
                $apiWarning = "Couldn't reach ContentiQ — showing imported entries only.";
            }
        } else {
            $apiWarning = 'ContentiQ API is not configured — showing imported entries only.';
        }

        // Graceful degradation: unconfigured or unreachable API falls back to
        // exactly the pre-existing local-only view (previously the only view
        // this screen ever rendered).
        if ($apiWarning !== null) {
            [$syncGroups, $hasSyncRecords] = $this->_buildLocalOnlySyncTree($sectionHandle, $collectionSections);
        }

        // Parse the project slug from the API key (ciq_{slug}_{32chars}) for display.
        $inferredSlug = '';
        $apiKey = App::parseEnv($settings->apiKey);

        if (str_starts_with($apiKey, 'ciq_')) {
            $withoutPrefix = substr($apiKey, 4);
            $lastUnderscore = strrpos($withoutPrefix, '_');

            if ($lastUnderscore !== false) {
                $inferredSlug = substr($withoutPrefix, 0, $lastUnderscore);
            }
        }

        // Globals consent — a single lightswitch above the Pages group. Missing
        // row ⇒ locked (the safe default).
        $globalsRow    = (new Query())
            ->select(['locked'])
            ->from('{{%contentiq_globals_sync}}')
            ->one();
        // craft\db\Query::one() returns null when no row matches.
        $globalsLocked = $globalsRow === null ? true : (bool)$globalsRow['locked'];

        $globalsOfficeCount = (int)(new Query())
            ->from('{{%contentiq_office_syncs}}')
            ->count();

        // Set by actionRunImport()'s redirect after it's already staged and
        // queued a run — tells sync.twig to skip the idle tree-selection
        // view and start polling this run immediately, labelled per
        // `runSource` ('upload' → "Upload") instead of the default "Sync".
        // Absent on a normal page load (the button-driven flow this screen
        // has always had).
        $initialRunId = Craft::$app->getRequest()->getQueryParam('runId');
        $runSource    = (string)Craft::$app->getRequest()->getQueryParam('source', 'sync');

        return $this->renderTemplate('contentiq-importer/_cp/sync', [
            'contentiqUrl'    => App::parseEnv($settings->contentiqUrl),
            'projectSlug'    => $inferredSlug,
            'hasSyncRecords' => $hasSyncRecords,
            'syncGroups'     => $syncGroups,
            'apiWarning'     => $apiWarning,
            'globalsLocked'     => $globalsLocked,
            'globalsOfficeCount' => $globalsOfficeCount,
            'globalsSetNames'    => ['companyInfo', 'globalContent', 'siteConfig'],
            'initialRunId'    => $initialRunId !== null ? (int)$initialRunId : null,
            'runSource'       => $runSource,
        ]);
    }

    /**
     * Builds the sync-screen entry tree from ONLY existing local sync records
     * (contentiq_entry_syncs) — no ContentIQ API call. This is the screen's
     * original behaviour, preserved as the fallback for when the API is
     * unconfigured or unreachable (see actionSync()).
     *
     * @param string $sectionHandle Pages section handle.
     * @param string[] $collectionSections Ordered list of collection section handles.
     * @return array{0: array, 1: bool} [$syncGroups, $hasSyncRecords]
     */
    private function _buildLocalOnlySyncTree(string $sectionHandle, array $collectionSections): array
    {
        $syncRecords = (new Query())
            ->select(['element_id', 'locked'])
            ->from('{{%contentiq_entry_syncs}}')
            ->all();

        $hasSyncRecords = count($syncRecords) > 0;
        $syncGroups     = [];

        if ($hasSyncRecords) {
            $elementIds = array_column($syncRecords, 'element_id');
            $lockedMap  = array_column($syncRecords, 'locked', 'element_id');

            // Query entries across the Pages section (+ homepage Single) and every
            // collection section, restricted to those that have a sync record.
            $entries = Entry::find()
                ->section(array_merge([$sectionHandle, 'homepage'], $collectionSections))
                ->id($elementIds)
                ->status(null)
                ->all();

            // Bucket entries by their display group. Homepage folds into Pages.
            $buckets = [];

            foreach ($entries as $entry) {
                $entrySection = $entry->section;
                $handle       = $entrySection->handle;
                $isHomepage   = $handle === 'homepage';
                $groupHandle  = $isHomepage ? $sectionHandle : $handle;
                $isStructure  = $entrySection->type === 'structure';
                $parent       = ($isStructure && !$isHomepage) ? $entry->getParent() : null;

                $buckets[$groupHandle][] = [
                    'elementId'  => $entry->id,
                    'title'      => $entry->title,
                    'slug'       => $entry->slug,
                    'locked'     => (bool)($lockedMap[$entry->id] ?? true),
                    'parentSlug' => $parent?->slug,
                    'depth'      => ($isStructure && !$isHomepage) ? max(0, $entry->level - 1) : 0,
                    'isHomepage' => $isHomepage,
                    'isNew'      => false,
                ];
            }

            // Build ordered groups: Pages first, then collection sections in config
            // order. Omit any group with no entries (e.g. a collection not yet synced).
            foreach (array_merge([$sectionHandle], $collectionSections) as $groupHandle) {
                if (empty($buckets[$groupHandle])) {
                    continue;
                }

                $section = Craft::$app->entries->getSectionByHandle($groupHandle);

                $syncGroups[] = [
                    'handle'  => $groupHandle,
                    'name'    => $section?->name ?? ucfirst($groupHandle),
                    'entries' => $buckets[$groupHandle],
                ];
            }
        }

        return [$syncGroups, $hasSyncRecords];
    }

    /**
     * Builds the sync-screen entry tree from the FULL ContentIQ project
     * export — every page/collection entry in the project, not just what's
     * already been imported.
     *
     * Each export page is matched against Craft via
     * ImportService::findExistingEntry() — the same id-first resolver
     * SyncJob's own lock check uses, so this view and an actual sync always
     * agree on what a page maps to. A match carries the entry's lock state
     * (missing sync row ⇒ locked, same default as SyncJob/the widget sync);
     * no match is a brand-new row (never locked — SyncJob's lock check only
     * runs when findExistingEntry() found something, so an unimported page
     * always imports regardless of any lock/selection state).
     *
     * Pages-group hierarchy (parent/child nesting) is built from the
     * export's own document.parent_slug — the only source that can link a
     * not-yet-imported child to an existing (or equally new) parent, since a
     * new page has no Craft structure position yet. Collection-entry rows
     * are deliberately kept flat (parentSlug forced null) — this mirrors
     * _buildLocalOnlySyncTree()'s behaviour (collection sections are
     * typically channels, which never carry a Craft parent) and avoids a
     * latent bug in the shared tree macro (sync.twig's per-group
     * childrenOf/rootSlugs build never checks whether a parentSlug is
     * actually present in the same group — a parent slug pointing outside
     * the group, e.g. a collection child's excluded collection-listing
     * parent, would make that row simply never render).
     *
     * An unmapped content_type (no content_types route) is skipped from the
     * tree entirely — mirrors ImportService::_importCollectionChild()'s own
     * non-fatal skip; there's no Craft section to display it under.
     *
     * @param array $data Decoded export payload (batch `{pages: [...]}` or single-page `{document: ...}`).
     * @param string $sectionHandle Pages section handle.
     * @param string[] $collectionSections Ordered list of collection section handles.
     * @return array Same shape as _buildLocalOnlySyncTree()'s $syncGroups.
     */
    private function _buildFullSyncTree(array $data, string $sectionHandle, array $collectionSections): array
    {
        $importService = ContentIQImporter::$plugin->imports;

        // Same batch/single-page detection SyncJob uses.
        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = [];

        if ($isBatch) {
            foreach ($data['pages'] as $pageData) {
                if (is_array($pageData)) {
                    $pages[] = $pageData;
                }
            }
        } elseif (isset($data['document']) && is_array($data['document'])) {
            $pages[] = $data;
        }

        // Local lock state, keyed by element_id — same source
        // _buildLocalOnlySyncTree() reads, consulted per matched entry below.
        $lockedMap = array_column(
            (new Query())->select(['element_id', 'locked'])->from('{{%contentiq_entry_syncs}}')->all(),
            'locked',
            'element_id',
        );

        $buckets = [];

        foreach ($pages as $pageData) {
            $document    = $pageData['document'] ?? [];
            $slug        = $document['slug'] ?? '';
            $contentType = $document['content_type'] ?? null;
            $isHomepage  = (bool)($document['is_homepage'] ?? false);

            $groupHandle = $sectionHandle;

            if ($contentType !== null) {
                $route = $importService->getContentTypeRoute($contentType);

                if ($route === null) {
                    continue;
                }

                $groupHandle = $route['section'];
            }

            $existingEntry = $importService->findExistingEntry($pageData);
            $elementId     = $existingEntry?->id;
            $isNew         = $elementId === null;
            $locked        = $isNew ? false : (bool)($lockedMap[$elementId] ?? true);

            $buckets[$groupHandle][] = [
                'elementId'       => $elementId,
                'title'           => $document['title'] ?? ($slug !== '' ? $slug : '(untitled)'),
                'slug'            => $slug,
                'locked'          => $locked,
                'parentSlug'      => $contentType === null ? ($document['parent_slug'] ?? null) : null,
                'depth'           => $document['depth'] ?? 0,
                'isHomepage'      => $isHomepage,
                'isNew'           => $isNew,
                'contentiqPageId' => isset($document['id']) ? (int)$document['id'] : null,
            ];
        }

        $syncGroups = [];

        foreach (array_merge([$sectionHandle], $collectionSections) as $groupHandle) {
            if (empty($buckets[$groupHandle])) {
                continue;
            }

            $section = Craft::$app->entries->getSectionByHandle($groupHandle);

            $syncGroups[] = [
                'handle'  => $groupHandle,
                'name'    => $section?->name ?? ucfirst($groupHandle),
                'entries' => $buckets[$groupHandle],
            ];
        }

        return $syncGroups;
    }

    /**
     * Starts the sync queue job.
     *
     * Creates a pending import run, pushes the job onto the queue,
     * and returns JSON with the run ID for the frontend to poll.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionRunSync(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('contentiq-importer:sync');

        $settings = ContentIQImporter::$plugin->getSettings();

        if ($settings->contentiqUrl === '' || $settings->apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error'   => 'ContentiQ API is not configured.',
            ]);
        }

        $service = ContentIQImporter::$plugin->syncRuns;
        $active  = $service->hasActiveRun();

        // Refuse a second concurrent sync — two near-simultaneous runs would
        // otherwise each rewrite contentiq_entry_syncs lock state and
        // clobber each other's selections. A stale active run (worker died,
        // nothing left queued for it) is failed and relocked instead of
        // blocking forever — then this request proceeds to start its own
        // run in the same request, same as the pre-pipeline behaviour of
        // just letting the next click through once the old one timed out.
        if ($active !== null) {
            if ($this->_isRunStale($active) && !$service->hasQueuedJob($active->queueJobId)) {
                $service->fail($active->id, 'Stale run failed when a new sync started');
                ContentIQImporter::$plugin->locks->relockGlobals('Stale run failed when a new sync started');
            } else {
                return $this->asJson([
                    'success' => false,
                    'error'   => 'A sync is already in progress — wait for it to finish.',
                ]);
            }
        }

        // Capture the lock/unlock selection from the pre-sync tree view and
        // the globals consent checkbox here, but DEFER actually applying
        // them until StageRunJob executes — writing them now, before the
        // job is even queued, would leak an unlock/consent if the worker
        // never picks up the job, or dies before running it. `null`
        // preserves the previous write's guard (`is_array($unlockIds)`): a
        // missing or malformed selection leaves lock state untouched
        // instead of locking everything.
        $request      = Craft::$app->getRequest();
        $unlockIdsRaw = Json::decodeIfJson($request->getBodyParam('unlockIds', '[]'));
        $unlockIds    = is_array($unlockIdsRaw) ? $unlockIdsRaw : null;

        // ContentIQ page ids for unchecked "New" rows — deliberately opt-OUT:
        // an absent/malformed param (broken JS, older cached page) means
        // nothing is excluded and every New page still imports, same as
        // before this feature existed. Stored under `options.newSelections`
        // — see SyncRun::$options / SyncJob::$excludeNewIds for the same
        // concept under its pre-pipeline name.
        $excludeNewIdsRaw = Json::decodeIfJson($request->getBodyParam('excludeNewIds', '[]'));
        $newSelections    = is_array($excludeNewIdsRaw)
            ? array_values(array_map('intval', array_filter($excludeNewIdsRaw, 'is_scalar')))
            : [];

        // The globals consent checkbox (checked = unlock for this run only).
        // StageRunJob persists the inverse into contentiq_globals_sync.locked
        // at the start of the run, then FinaliseRunJob relocks after a
        // successful globals import — or on any failure, every job's own
        // catch block does.
        $unlockGlobals = (bool)$request->getBodyParam('unlockGlobals', false);

        $run = $service->createRun('api', [
            'unlockIds'     => $unlockIds,
            'unlockGlobals' => $unlockGlobals,
            'newSelections' => $newSelections,
            'file'          => null,
            'dryRun'        => false,
        ], Craft::$app->getUser()->getId(), 'sync');

        $jobId = QueueHelper::push(new StageRunJob(['runId' => $run->id]), null, 0, SyncQueueConfig::ttr());
        $service->setQueueJobId($run->id, $jobId !== null ? (int)$jobId : null);

        return $this->asJson([
            'success' => true,
            'runId'   => $run->id,
        ]);
    }

    /**
     * Polls the status of a sync run.
     *
     * Returns `{status, phase, step, counts, progressLabel, stale}`. `status`
     * keeps the legacy vocabulary `sync.twig`'s `pollForCompletion()` already
     * polls on (`pending` while not terminal, then `success`|`warnings`|
     * `errors`) so its "still polling?" check needs no change; `phase`/`step`
     * drive the progress bar, and `phase === 'failed'` is what tells the
     * frontend to offer Resume instead of redirecting to the report.
     *
     * @return Response
     */
    public function actionSyncStatus(): Response
    {
        $this->requireAcceptsJson();

        $runId   = (int)Craft::$app->getRequest()->getRequiredQueryParam('runId');
        $service = ContentIQImporter::$plugin->syncRuns;
        $run     = $service->loadRun($runId);

        if ($run === null) {
            return $this->asJson(['status' => 'unknown']);
        }

        // Terminal — `done` (legacy runs included: the migration backfilled
        // every pre-pipeline finished run to `phase='done'`, so `run.status`
        // is exactly what it always was for them) or `failed` (`run.status`
        // is never touched by fail() — see SyncRunService::fail() — so it's
        // read here from `phase` instead, not the stale 'pending' the status
        // column would otherwise still say).
        if ($run->isTerminal()) {
            return $this->asJson([
                'status'        => $run->phase === SyncRun::PHASE_FAILED ? 'errors' : ($run->status ?: 'unknown'),
                'phase'         => $run->phase,
                'step'          => $run->step,
                'counts'        => $service->counts($runId),
                'progressLabel' => null,
                'stale'         => false,
            ]);
        }

        // Staleness: `heartbeat` (touched by every job execution/item) older
        // than the configured threshold AND nothing left queued for this
        // run's `queueJobId` — the job died without a failure handler
        // running (a killed worker, an OOM), so the poller would otherwise
        // spin on 'pending' forever. A false positive only ever surfaces an
        // error message the user can Resume past, so this stays deliberately
        // generous, same reasoning as the pre-pipeline staleness fallback.
        if ($this->_isRunStale($run) && !$service->hasQueuedJob($run->queueJobId)) {
            $message = 'Sync did not complete — the queue worker may not be running.';
            $service->fail($runId, $message);
            ContentIQImporter::$plugin->locks->relockGlobals($message);

            return $this->asJson([
                'status'        => 'errors',
                'phase'         => SyncRun::PHASE_FAILED,
                'step'          => null,
                'counts'        => $service->counts($runId),
                'progressLabel' => null,
                'stale'         => true,
            ]);
        }

        $counts = $service->counts($runId);

        return $this->asJson([
            'status'        => 'pending',
            'phase'         => $run->phase,
            'step'          => $run->step,
            'counts'        => $counts,
            'progressLabel' => $this->_progressLabel($run, $counts),
            'stale'         => false,
        ]);
    }

    /**
     * Sync result screen — hierarchical report of a sync run.
     *
     * Pipeline runs (any run with contentiq_sync_pages rows) build the
     * report from those rows, paginated at 200 — this works identically
     * whether the run is still going (the template gets `running: true` and
     * shows a banner) or already `done` (a `done` run's rows are exactly
     * what FinaliseRunJob's `report` step itself read to assemble the
     * legacy `result` blob, so reading them again here is just as accurate
     * and avoids re-decoding + re-slicing that blob for pagination). Runs
     * that predate the pipeline (no sync_pages rows — widget syncs, or any
     * run older than the m260921_100000 migration) fall back to decoding
     * `result` directly, exactly as before this change.
     *
     * @param int $runId
     * @return Response
     */
    public function actionSyncResult(int $runId): Response
    {
        $service = ContentIQImporter::$plugin->syncRuns;
        $run     = $service->loadRun($runId);

        if ($run === null) {
            throw new \yii\web\NotFoundHttpException('Sync run not found.');
        }

        if (!$service->hasSyncPages($runId)) {
            $legacyRow = (new Query())
                ->from('{{%contentiq_import_runs}}')
                ->where(['id' => $runId])
                ->one();

            $legacyRow['result'] = Json::decodeIfJson($legacyRow['result'] ?? '[]');

            return $this->renderTemplate('contentiq-importer/_cp/sync-result', [
                'run'        => $legacyRow,
                'running'    => false,
                'page'       => 1,
                'totalPages' => 1,
            ]);
        }

        $perPage    = 200;
        $page       = max(1, (int)Craft::$app->getRequest()->getQueryParam('p', 1));
        $totalRows  = $service->pageRowCount($runId);
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $page       = min($page, $totalPages);

        $rows  = $service->pageRows($runId, ($page - 1) * $perPage, $perPage);
        $pages = [];

        foreach ($rows as $row) {
            // A `pending` row hasn't been processed yet (the run is still
            // going) — it carries no `result` at all. Rendering it as a
            // page-result row would show it as "Failed" (the template's
            // `page.success ?? false` falls through to the red/failed
            // branch for a missing key) — omit it instead; the "still
            // running" banner + progress bar on the status screen already
            // tell the user more is coming.
            if (is_array($row['result'])) {
                $pages[] = $row['result'];
            }
        }

        // Malformed export entries never got an individual result row (see
        // SyncPlanner::plan()) — surfaced as one synthetic combined warning
        // row on page 1 only, matching FinaliseRunJob::_runReportStep()'s
        // own single combined entry.
        if ($page === 1) {
            $skippedMalformed = $service->skippedMalformedCount($runId);

            if ($skippedMalformed > 0) {
                array_unshift($pages, [
                    'success'      => true,
                    'slug'         => '',
                    'entryId'      => null,
                    'entryFound'   => false,
                    'title'        => 'Malformed export entries',
                    'depth'        => 0,
                    'parentSlug'   => null,
                    'blocks'       => [],
                    'images'       => [],
                    'blockNotes'   => '',
                    'seoFieldCount' => 0,
                    'warnings'     => ["{$skippedMalformed} page(s) in the export were malformed (not an object) and were skipped."],
                    'error'        => null,
                    'contentType'  => null,
                    'sectionLabel' => null,
                ]);
            }
        }

        $legacyStatus = $run->phase === SyncRun::PHASE_FAILED ? 'errors' : ($run->status ?: 'pending');

        return $this->renderTemplate('contentiq-importer/_cp/sync-result', [
            'run' => [
                'id'     => $run->id,
                'status' => $legacyStatus,
                'result' => [
                    'pages'      => $pages,
                    'globals'    => $run->state['globalsReport'] ?? null,
                    'ackWarning' => $run->state['ackWarning'] ?? null,
                ],
            ],
            'running'    => !$run->isTerminal(),
            'page'       => $page,
            'totalPages' => $totalPages,
        ]);
    }

    /**
     * Resumes a `failed` run from the phase/step {@see SyncRunService::fail()}
     * recorded for it, re-pushing the matching job class (§3.7's Resume
     * button, and SyncController::actionResume()'s CLI equivalent). Refuses
     * when something is already queued for the run (a double-click, or a
     * race with an in-flight retry) — the run row is the concurrency lock,
     * same principle as StageRunJob's own guard (§3.6).
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionResumeRun(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('contentiq-importer:sync');

        $runId   = (int)Craft::$app->getRequest()->getRequiredBodyParam('runId');
        $service = ContentIQImporter::$plugin->syncRuns;
        $run     = $service->loadRun($runId);

        if ($run === null) {
            return $this->asJson(['success' => false, 'error' => 'Sync run not found.']);
        }

        if ($run->phase !== SyncRun::PHASE_FAILED) {
            return $this->asJson(['success' => false, 'error' => 'Only a failed run can be resumed.']);
        }

        if ($service->hasQueuedJob($run->queueJobId)) {
            return $this->asJson(['success' => false, 'error' => 'A job is already queued for this run.']);
        }

        $phase = $run->state['failedPhase'] ?? null;
        $step  = $run->state['failedStep'] ?? null;

        $jobClass = match ($phase) {
            SyncRun::PHASE_STAGING    => StageRunJob::class,
            SyncRun::PHASE_IMPORTING  => ImportPagesJob::class,
            SyncRun::PHASE_POSTPASS   => PostPassJob::class,
            SyncRun::PHASE_FINALISING => FinaliseRunJob::class,
            default                   => null,
        };

        if ($jobClass === null) {
            return $this->asJson(['success' => false, 'error' => 'This run has no recorded phase to resume from.']);
        }

        $service->advance($runId, $phase, $step);
        // Refresh the heartbeat the staleness check reads — without this, a
        // run resumed after sitting failed for a while (heartbeat untouched
        // since its last job execution) would look stale again on the very
        // next status poll, before a worker even gets a chance to pick the
        // newly-pushed job up.
        $service->touch($runId);

        $ttr   = $phase === SyncRun::PHASE_FINALISING ? SyncQueueConfig::finaliseTtr() : SyncQueueConfig::ttr();
        $jobId = QueueHelper::push(new $jobClass(['runId' => $runId]), null, 0, $ttr);
        $service->setQueueJobId($runId, $jobId !== null ? (int)$jobId : null);

        return $this->asJson(['success' => true, 'runId' => $runId]);
    }

    /**
     * Syncs a single entry from the ContentIQ API.
     *
     * Called via AJAX from the ContentIQ sidebar widget on the entry edit screen.
     * Resolves the ContentIQ locator for this entry (see the locator
     * resolution block below — slugMap override, stored ContentIQ id,
     * homepage Single, or the Craft slug), fetches that page's export, runs
     * it through ImportService, and upserts a row in contentiq_entry_syncs
     * on success. A 404 falls back to the plain Craft slug when the locator
     * came from a stored id or the homepage guess, and produces a message
     * that explains why and what to do about it — see _readNotFoundReason().
     *
     * Request body: { elementId: int, slug: string }
     * Response:     { success: bool, syncedAt?: string, error?: string }
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionWidgetSync(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('contentiq-importer:sync');

        $request   = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $slug      = trim((string)$request->getRequiredBodyParam('slug'));

        if (!$elementId || $slug === '') {
            return $this->asJson(['success' => false, 'error' => 'elementId and slug are required.']);
        }

        // A widget sync is a run of one page — resets per-run state
        // (currently just the "first global CTA block wins" tracking — see
        // ImportService::beginRun()) so it never inherits state from a
        // previous run in the same PHP process.
        ContentIQImporter::$plugin->imports->beginRun();

        // Enforce the lock server-side. The sidebar Sync button is disabled
        // client-side when locked, but that's advisory only — a stale page or a
        // direct request to this endpoint could still reach here. The widget
        // targets a specific, already-saved entry (elementId), so the lock is
        // checked against that entry directly rather than re-resolving it from
        // the fetched page data. A missing sync row is treated as locked (same
        // default as the Sync UI / SyncJob).
        // Select contentiq_page_id alongside locked so locator step 2 below
        // (a previously synced ContentIQ id) reuses this one query instead of
        // a second round trip to the same table.
        $lockRow = (new Query())
            ->select(['locked', 'contentiq_page_id'])
            ->from('{{%contentiq_entry_syncs}}')
            ->where(['element_id' => $elementId])
            ->one();

        // craft\db\Query::one() returns null when no row matches.
        if ($lockRow === null || (bool)$lockRow['locked']) {
            return $this->asJson([
                'success' => false,
                'error'   => 'Entry is locked — unlock before syncing.',
            ]);
        }

        // Loaded once: the title below is a user-facing label, and locator
        // step 3 (homepage detection) needs the same entry's section.
        $entry      = Entry::find()->id($elementId)->status(null)->one();
        $entryTitle = $entry !== null ? ($entry->title ?: $slug) : $slug;

        $settings = ContentIQImporter::$plugin->getSettings();

        if ($settings->contentiqUrl === '' || $settings->apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error'   => 'ContentiQ API is not configured. Set URL and API key in plugin settings.',
            ]);
        }

        $config  = Craft::$app->config->getConfigFromFile('contentiq');
        $slugMap = $config['slugMap'] ?? [];

        // Resolve the ContentIQ locator — first hit wins:
        //   1. slugMap[$slug]                    — explicit override, always wins.
        //   2. contentiq_entry_syncs.contentiq_page_id for this entry — the
        //      numeric id a previous sync (batch or widget) already stored.
        //   3. The entry is the homepage Single  — '__home__'.
        //   4. Otherwise                         — the Craft slug.
        // $locatorSource feeds the 404 messages below so they can say why the
        // lookup failed and what to do about it.
        if (isset($slugMap[$slug])) {
            $locator       = $slugMap[$slug];
            $locatorSource = 'slugMap';
        } elseif (!empty($lockRow['contentiq_page_id'])) {
            $locator       = (string)$lockRow['contentiq_page_id'];
            $locatorSource = 'stored id';
        } elseif ($this->_isHomepageEntry($entry, $config)) {
            $locator       = '__home__';
            $locatorSource = 'homepage';
        } else {
            $locator       = $slug;
            $locatorSource = 'slug';
        }

        $apiKey   = App::parseEnv($settings->apiKey);
        $url      = rtrim(App::parseEnv($settings->contentiqUrl), '/');
        $endpoint = "{$url}/api/v1/pages/{$locator}/export";

        try {
            $response = $this->_fetchExport($endpoint, $apiKey);
        } catch (GuzzleException $e) {
            $status = method_exists($e, 'getResponse') && $e->getResponse() !== null
                ? $e->getResponse()->getStatusCode()
                : 0;

            if ($status !== 404) {
                return $this->asJson(['success' => false, 'error' => 'API request failed: ' . $e->getMessage()]);
            }

            [$reason, $reasonStatus] = $this->_readNotFoundReason($e);

            if ($reason === 'not_ready') {
                return $this->asJson([
                    'success' => false,
                    'error'   => "'{$entryTitle}' is not ready for export in ContentiQ (status: {$reasonStatus}). Set it to Ready for Export there first.",
                ]);
            }

            // $reason === 'unknown_page', or absent (a ContentiQ deployment
            // that predates the {reason} body): a locator resolved from a
            // stored id or the homepage guess can be stale — the stored id
            // from a page that was since removed, or a __home__ guess a
            // pre-change ContentiQ doesn't understand. Retry once against the
            // plain Craft slug before giving up. A locator that was already
            // the slug has nothing left to fall back to.
            $response = null;

            if (($locatorSource === 'stored id' || $locatorSource === 'homepage') && $locator !== $slug) {
                try {
                    $response = $this->_fetchExport("{$url}/api/v1/pages/{$slug}/export", $apiKey);
                } catch (GuzzleException $retryException) {
                    $retryStatus = method_exists($retryException, 'getResponse') && $retryException->getResponse() !== null
                        ? $retryException->getResponse()->getStatusCode()
                        : 0;

                    // Only a second 404 means "no such page". Anything else
                    // (timeout, 5xx, 401) is an infrastructure failure and
                    // must be reported as such, exactly like the primary call.
                    if ($retryStatus !== 404) {
                        return $this->asJson([
                            'success' => false,
                            'error'   => 'API request failed: ' . $retryException->getMessage(),
                        ]);
                    }

                    // A not_ready reason on the retry is more specific than
                    // "no page" — surface it the same way the primary call does.
                    [$retryReason, $retryReasonStatus] = $this->_readNotFoundReason($retryException);

                    if ($retryReason === 'not_ready') {
                        return $this->asJson([
                            'success' => false,
                            'error'   => "'{$entryTitle}' is not ready for export in ContentiQ (status: {$retryReasonStatus}). Set it to Ready for Export there first.",
                        ]);
                    }

                    $response = null;
                }
            }

            if ($response === null) {
                return $this->asJson([
                    'success' => false,
                    'error'   => "ContentiQ has no page for '{$entryTitle}' (looked up by {$locatorSource}: '{$locator}'). "
                        . 'If the ContentiQ slug differs from the Craft slug, add a slugMap entry to config/contentiq.php '
                        . "(Craft slug → ContentiQ slug), or run a full Sync from the ContentiQ Sync screen once so this "
                        . "entry's ContentiQ id is stored.",
                ]);
            }
        }

        $body = $response->getBody()->getContents();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return $this->asJson(['success' => false, 'error' => 'ContentiQ returned invalid JSON.']);
        }

        // Run the import pipeline (no dry-run).
        $result = ContentIQImporter::$plugin->imports->importPage($data, dryRun: false);

        if (!$result['success']) {
            // Fix D1: importPage() above may have attempted to write content —
            // record it in the audit trail the same as every other entry point,
            // rather than leaving this a content mutation with no history row.
            $this->_saveRun(
                filename:   $slug,
                type:       'widget',
                pageCount:  0,
                imageCount: 0,
                status:     'errors',
                result:     [$result],
            );

            return $this->asJson([
                'success' => false,
                'error'   => $result['error'] ?? 'Import failed.',
            ]);
        }

        // Stable ContentIQ page id (when the fetched page carried one) — needed
        // below for runPostPasses()'s "genuinely written" predicate as well as
        // the contentiq_page_id mapping and the ack call further down.
        $pageId = isset($data['document']['id']) ? (int)$data['document']['id'] : null;

        // Post-passes: card references (pass 2) + link sweep (pass 3), single
        // page — no in-run slug map, DB lookups only. $oneRow mirrors the row
        // shape SyncJob/CpController batch build: $result plus the fields
        // ImportService::_writtenPages() reads (contentiqPageId, and the three
        // skip flags left as their already-correct default here — this is a
        // real write, gated on the lock check above).
        if (($result['entryId'] ?? null) !== null) {
            $entryId     = $result['entryId'];
            $allCardRefs = !empty($result['cardRefs']) ? [$entryId => array_values($result['cardRefs'])] : [];

            $oneRow = $result + [
                'contentiqPageId'   => $pageId,
                'skippedLocked'     => false,
                'skippedDeselected' => false,
                'skipped'           => false,
            ];

            // Guarded (see actionRunImport()'s matching try/catch for the
            // full reasoning) — a throw from the sweeps' own setup queries
            // must not produce a raw 500 and skip the sync-timestamp upsert/
            // ack/lock steps below, even though the entry was already
            // written. Falls back to a synthetic warning in the exact same
            // shape ($postPassWarnings[$entryId] => string[]) the foreach
            // loop below already expects on success.
            try {
                $postPassWarnings = ContentIQImporter::$plugin->imports->runPostPasses($allCardRefs, [], [$oneRow]);
            } catch (\Throwable $e) {
                Craft::error(
                    'ContentIQImporter: widget sync post-import passes failed — ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                    __METHOD__,
                );

                $postPassWarnings = [
                    $entryId => ['Post-import passes (card references / link sweep) failed: ' . $e->getMessage()],
                ];
            }

            foreach ($postPassWarnings[$entryId] ?? [] as $warning) {
                Craft::warning("Widget sync post-passes ({$slug}): {$warning}", __METHOD__);
            }
        }

        // Upsert the sync timestamp and notes.
        $now   = Db::prepareDateForDb(new \DateTime());
        $notes = $result['blockNotes'] ?? '';
        $db    = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%contentiq_entry_syncs}}')
            ->where(['element_id' => $elementId])
            ->exists();

        // sort_order keeps this entry's stored ContentIQ sibling order current
        // even though the widget sync itself never repositions anything — a
        // later batch sync reads it via ImportService::positionInStructure()
        // to place THIS entry's siblings correctly. 0 is a legitimate
        // first-sibling value, so it's always written, not guarded behind
        // !empty() like contentiq_page_id below.
        $syncData = [
            'synced_at'  => $now,
            'notes'      => $notes,
            'locked'     => true,
            'sort_order' => (int)($data['document']['sort_order'] ?? 0),
        ];

        // Record the ContentIQ page id → element_id mapping (when the fetched
        // page carried one) so findExistingEntry() can resolve this entry by
        // id on the next sync even if its slug changes.
        if ($pageId !== null) {
            $syncData['contentiq_page_id'] = $pageId;
        }

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_entry_syncs}}', $syncData, ['element_id' => $elementId])
                ->execute();
        } else {
            $db->createCommand()
                ->insert('{{%contentiq_entry_syncs}}', array_merge(['element_id' => $elementId], $syncData))
                ->execute();
        }

        // Acknowledge this page with ContentiQ so it can retire its own
        // pending-import state — same contract SyncJob uses for batch syncs.
        // Best-effort: never fails the widget sync.
        if ($pageId !== null) {
            $ackResult = ContentIQImporter::$plugin->api->ackPages([$pageId]);

            if (!$ackResult['success']) {
                Craft::warning(
                    "ContentIQImporter: widget sync ack failed for page {$pageId} ({$slug}) — " . ($ackResult['error'] ?? 'unknown error'),
                    __METHOD__,
                );
            }
        }

        $syncedAt = Craft::$app->getFormatter()->asDatetime($now, 'short');

        // Fix D1: record this widget sync in the audit trail, same as every
        // other content-mutating entry point (upload/import, sync, console).
        $this->_saveRun(
            filename:   $slug,
            type:       'widget',
            pageCount:  1,
            imageCount: count($result['images'] ?? []),
            status:     !empty($result['warnings']) ? 'warnings' : 'success',
            result:     [$result],
        );

        return $this->asJson(['success' => true, 'syncedAt' => $syncedAt, 'notes' => $notes]);
    }

    /**
     * Toggles the lock state for an entry's ContentIQ sync record.
     *
     * Locked entries are skipped during batch/full syncs.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionToggleLock(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('contentiq-importer:sync');

        $request   = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $locked    = (bool)$request->getRequiredBodyParam('locked');

        $db = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%contentiq_entry_syncs}}')
            ->where(['element_id' => $elementId])
            ->exists();

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_entry_syncs}}', ['locked' => $locked], ['element_id' => $elementId])
                ->execute();
        } else {
            $db->createCommand()
                ->insert('{{%contentiq_entry_syncs}}', [
                    'element_id' => $elementId,
                    'locked'     => $locked,
                    'synced_at'  => Db::prepareDateForDb(new \DateTime()),
                ])
                ->execute();
        }

        return $this->asJson(['success' => true, 'locked' => $locked]);
    }

    /**
     * Clears the notes for an entry's ContentIQ sync record.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionClearNotes(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('contentiq-importer:sync');

        $elementId = (int)Craft::$app->getRequest()->getRequiredBodyParam('elementId');

        Craft::$app->getDb()->createCommand()
            ->update('{{%contentiq_entry_syncs}}', ['notes' => ''], ['element_id' => $elementId])
            ->execute();

        return $this->asJson(['success' => true]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Performs the ContentIQ export GET for one locator.
     *
     * Factored out of actionWidgetSync() purely so its 404 retry-on-slug
     * branch can reissue the same request against a different locator
     * without duplicating the Guzzle call.
     *
     * @param string $endpoint Full export URL (…/api/v1/pages/{locator}/export).
     * @param string $apiKey   Bearer token, already App::parseEnv()'d.
     * @return ResponseInterface
     * @throws GuzzleException
     */
    private function _fetchExport(string $endpoint, string $apiKey): ResponseInterface
    {
        return Craft::createGuzzleClient()->request('GET', $endpoint, [
            RequestOptions::HEADERS => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $apiKey,
            ],
            RequestOptions::TIMEOUT         => 30,
            RequestOptions::CONNECT_TIMEOUT => 10,
        ]);
    }

    /**
     * Reads the {reason, status} pair from a 404 export response body.
     *
     * ContentIQ's export 404s carry `reason` ('unknown_page' | 'not_ready')
     * and, for 'not_ready', the page's current `status`. A body that isn't
     * valid JSON, or carries no 'reason' key (a ContentIQ deployment that
     * predates this contract), yields [null, null] — actionWidgetSync()
     * treats that the same as 'unknown_page'.
     *
     * @param GuzzleException $e
     * @return array{0: ?string, 1: ?string}
     */
    private function _readNotFoundReason(GuzzleException $e): array
    {
        if (!method_exists($e, 'getResponse') || $e->getResponse() === null) {
            return [null, null];
        }

        $body = $e->getResponse()->getBody()->getContents();
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return [null, null];
        }

        return [$data['reason'] ?? null, $data['status'] ?? null];
    }

    /**
     * Whether $entry is the project's homepage Single.
     *
     * Used by actionWidgetSync()'s locator inference (step 3: '__home__').
     * Detected either of two ways: the entry's section handle matches the
     * configured homepage section, or the section is a Single whose
     * uriFormat for the entry's site is Craft's homepage marker '__home__'.
     *
     * @param Entry|null $entry  Loaded once by the caller; null short-circuits to false.
     * @param array      $config Project config (config/contentiq.php), unmerged with defaults.
     * @return bool
     */
    private function _isHomepageEntry(?Entry $entry, array $config): bool
    {
        if ($entry === null) {
            return false;
        }

        $section = $entry->getSection();

        if ($section === null) {
            return false;
        }

        $homepageSection = $config['homepageSection'] ?? 'homepage';

        if ($section->handle === $homepageSection) {
            return true;
        }

        if ($section->type !== Section::TYPE_SINGLE) {
            return false;
        }

        $siteSettings = $section->getSiteSettings()[$entry->siteId] ?? null;

        return $siteSettings !== null && (string)$siteSettings->uriFormat === '__home__';
    }

    /**
     * Builds the cascade data for the Mappings screen: every section with its
     * entry types, and each entry type's CKEditor and PlainText field handles.
     *
     * Embedded as JSON so the vanilla-JS dropdowns can cascade section → entry
     * type → content field (CKEditor) / heading field (PlainText) / blocks
     * field (Matrix).
     *
     * @return array<int, array{handle: string, name: string, entryTypes: array<int, array{handle: string, name: string, ckeditorFields: array<int, array{handle: string, name: string}>, plainTextFields: array<int, array{handle: string, name: string}>, matrixFields: array<int, array{handle: string, name: string}>}>}>
     */
    private function _buildSectionsData(): array
    {
        $data = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $entryTypes = [];

            foreach ($section->getEntryTypes() as $entryType) {
                $ckeditorFields  = [];
                $plainTextFields = [];
                $matrixFields    = [];

                foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
                    // CKEditor is an optional dependency — reference it by its
                    // fully-qualified name so this works without the plugin.
                    if ($field instanceof \craft\ckeditor\Field) {
                        $ckeditorFields[] = ['handle' => $field->handle, 'name' => $field->name];
                    } elseif ($field instanceof PlainText) {
                        $plainTextFields[] = ['handle' => $field->handle, 'name' => $field->name];
                    } elseif ($field instanceof Matrix) {
                        $matrixFields[] = ['handle' => $field->handle, 'name' => $field->name];
                    }
                }

                $entryTypes[] = [
                    'handle'          => $entryType->handle,
                    'name'            => $entryType->name,
                    'ckeditorFields'  => $ckeditorFields,
                    'plainTextFields' => $plainTextFields,
                    'matrixFields'    => $matrixFields,
                ];
            }

            $data[] = [
                'handle'     => $section->handle,
                'name'       => $section->name,
                'entryTypes' => $entryTypes,
            ];
        }

        return $data;
    }

    /**
     * Whether a non-terminal run's `heartbeat` (touched by every job
     * execution/item) is old enough that the run is treated as abandoned
     * rather than genuinely in flight — e.g. a queue worker that isn't
     * running, or one that died before any job's own catch block could mark
     * it failed. Shared by the status poller ({@see self::actionSyncStatus()})
     * and the concurrency guard ({@see self::actionRunSync()}). A
     * false-positive stale flag only ever surfaces an error message the user
     * can Resume past, so the threshold ({@see SyncQueueConfig::staleAfter()},
     * default 600s) is deliberately generous.
     *
     * @param SyncRun $run
     * @return bool
     */
    private function _isRunStale(SyncRun $run): bool
    {
        if ($run->heartbeat === null || $run->heartbeat === '') {
            return false;
        }

        try {
            $updated = new \DateTime($run->heartbeat, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return false;
        }

        $ageSeconds = (new \DateTime('now', new \DateTimeZone('UTC')))->getTimestamp() - $updated->getTimestamp();

        return $ageSeconds >= SyncQueueConfig::staleAfter();
    }

    /**
     * Human-readable progress label for a non-terminal run, driven by
     * `phase`/`step` and (for `importing`) the row counts — what
     * `sync.twig`'s progress bar renders. Terminal runs pass `null`
     * (`progressLabel` in the status JSON) — the frontend redirects/shows an
     * error instead of continuing to show progress text.
     *
     * @param SyncRun $run
     * @param array $counts {@see SyncRunService::counts()}'s return shape.
     * @return string
     */
    private function _progressLabel(SyncRun $run, array $counts): string
    {
        return match ($run->phase) {
            SyncRun::PHASE_STAGING => 'Preparing…',
            SyncRun::PHASE_IMPORTING => $counts['total'] > 0
                ? 'Importing page ' . min($counts['total'] - $counts['pending'] + 1, $counts['total']) . ' of ' . $counts['total']
                : 'Importing…',
            SyncRun::PHASE_POSTPASS => 'Resolving links…',
            SyncRun::PHASE_FINALISING => match ($run->step) {
                SyncRun::STEP_ACK     => 'Acknowledging imported pages…',
                SyncRun::STEP_GLOBALS => 'Importing globals…',
                SyncRun::STEP_LOCK    => 'Locking synced entries…',
                default                => 'Finalising…',
            },
            default => 'Working…',
        };
    }

    /**
     * Saves an import run to the history table.
     *
     * @param string $filename
     * @param string $type
     * @param int    $pageCount
     * @param int    $imageCount
     * @param string $status
     * @param array  $result
     * @return int The inserted row ID.
     */
    private function _saveRun(
        string $filename,
        string $type,
        int $pageCount,
        int $imageCount,
        string $status,
        array $result,
    ): int {
        $db = Craft::$app->getDb();

        $db->createCommand()->insert('{{%contentiq_import_runs}}', [
            'importedBy'  => Craft::$app->getUser()->getId(),
            'filename'    => $filename,
            'type'        => $type,
            'pageCount'   => $pageCount,
            'imageCount'  => $imageCount,
            'status'      => $status,
            'result'      => Json::encode($result),
            'dateCreated' => Db::prepareDateForDb(new \DateTime()),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
            'uid'         => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }
}
