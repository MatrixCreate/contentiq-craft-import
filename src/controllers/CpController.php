<?php

namespace matrixcreate\contentiqimporter\controllers;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\web\Controller;
use craft\web\UploadedFile;
use craft\helpers\StringHelper;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;
use matrixcreate\contentiqimporter\ContentIQImporter;
use matrixcreate\contentiqimporter\jobs\SyncJob;
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
            ->select(['id', 'importedBy', 'filename', 'type', 'pageCount', 'imageCount', 'status', 'dateCreated'])
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

            $mappings[(string)$slug] = [
                'section'      => $section,
                'entryType'    => $entryType,
                'contentField' => $contentField,
                'headingField' => $headingField !== '' ? $headingField : null,
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

        $previewResults = [];
        $totalWarnings  = 0;

        foreach ($pages as $pageData) {
            $result = $importService->importPage($pageData, dryRun: true);

            // Check if entry exists.
            $slug     = $result['slug'] ?? '';
            $existing = Entry::find()->section('pages')->slug($slug)->status(null)->one();

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
     * Runs the actual import and redirects to the result screen.
     *
     * @return Response
     * @throws BadRequestHttpException
     */
    public function actionRunImport(): Response
    {
        $this->requirePostRequest();

        $tempFilename = Craft::$app->getRequest()->getRequiredBodyParam('tempFilename');
        $tempPath     = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . $tempFilename;

        if (!is_file($tempPath)) {
            Craft::$app->getSession()->setError('Import file expired. Please upload again.');

            return $this->redirect('contentiq-importer/upload');
        }

        $json = file_get_contents($tempPath);
        $data = json_decode($json, true);

        if ($data === null) {
            Craft::$app->getSession()->setError('Could not parse import file.');

            return $this->redirect('contentiq-importer/upload');
        }

        // Web requests run from webroot already — no chdir needed (unlike CLI).

        $isBatch = isset($data['pages']) && is_array($data['pages']);
        $pages   = $isBatch ? $data['pages'] : [$data];

        $importService = ContentIQImporter::$plugin->imports;
        $pageResults   = [];
        $totalImages   = 0;
        $hasErrors     = false;
        $hasWarnings   = false;
        $slugToEntryId = [];

        // Resolve section for structure positioning.
        $config        = Craft::$app->config->getConfigFromFile('contentiq');
        $sectionHandle = $config['section'] ?? 'pages';
        $section       = Craft::$app->entries->getSectionByHandle($sectionHandle);
        $structureId   = $section?->structureId;
        $structures    = Craft::$app->getStructures();

        foreach ($pages as $pageData) {
            $result = $importService->importPage($pageData, dryRun: false);

            $slug       = $result['slug'] ?? '';
            $parentSlug = $pageData['document']['parent_slug'] ?? null;
            $entryId    = $result['entryId'] ?? null;
            $isHomepage = (bool)($pageData['document']['is_homepage'] ?? false);

            if ($entryId !== null && $slug !== '') {
                $slugToEntryId[$slug] = $entryId;
            }

            if ($entryId !== null && $structureId !== null && !$isHomepage) {
                $entry = Entry::find()->id($entryId)->status(null)->one();

                if ($entry !== null) {
                    try {
                        if ($parentSlug !== null && $parentSlug !== '') {
                            // Try current-batch map first, then fall back to a Craft query
                            // so re-imports correctly place children under existing parents.
                            $parentId = $slugToEntryId[$parentSlug] ?? null;

                            if ($parentId === null) {
                                $parentEntry = Entry::find()
                                    ->section($sectionHandle)
                                    ->slug($parentSlug)
                                    ->status(null)
                                    ->one();
                                $parentId = $parentEntry?->id;
                            }

                            if ($parentId !== null) {
                                $structures->append($structureId, $entry, $parentId);
                            } else {
                                $structures->appendToRoot($structureId, $entry);
                                $result['warnings'][] = "Parent slug '{$parentSlug}' not found — entry saved at root level.";
                            }
                        } else {
                            $structures->appendToRoot($structureId, $entry);
                        }
                    } catch (\Throwable $e) {
                        $result['warnings'][] = 'Could not update structure position: ' . $e->getMessage();
                    }
                }
            }

            $pageResults[] = $result;
            $totalImages  += count($result['images'] ?? []);

            if (!$result['success']) {
                $hasErrors = true;
            }
            if (!empty($result['warnings'])) {
                $hasWarnings = true;
            }
        }

        // Determine overall status.
        if ($hasErrors) {
            $status = 'errors';
        } elseif ($hasWarnings) {
            $status = 'warnings';
        } else {
            $status = 'success';
        }

        // Save to history.
        $runId = $this->_saveRun(
            filename:   basename($tempFilename),
            type:       $isBatch ? 'batch' : 'single',
            pageCount:  count($pageResults),
            imageCount: $totalImages,
            status:     $status,
            result:     $pageResults,
        );

        // Clean up temp file.
        @unlink($tempPath);

        // The upload path intentionally does not import globals — that needs the
        // lock/consent model, which only the Sync screen exposes. Flag it so the
        // editor knows the globals in the file were left untouched.
        if (isset($data['globals']) && is_array($data['globals']) && !empty($data['globals'])) {
            Craft::$app->getSession()->setNotice(
                Craft::t('contentiq-importer', 'Globals present in file — use Sync to import globals.'),
            );
        }

        return $this->redirect('contentiq-importer/result/' . $runId);
    }

    /**
     * Result screen — shows the outcome of a completed import run.
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

        $run['result'] = Json::decodeIfJson($run['result'] ?? '[]');

        return $this->renderTemplate('contentiq-importer/_cp/result', [
            'run' => $run,
        ]);
    }

    /**
     * Sync screen — shows API details and sync button.
     *
     * @return Response
     */
    public function actionSync(): Response
    {
        $settings = ContentIQImporter::$plugin->getSettings();

        if ($settings->contentiqUrl === '' || $settings->apiKey === '') {
            Craft::$app->getSession()->setError('ContentiQ API is not configured. Set URL and API key in plugin settings.');

            return $this->redirect('contentiq-importer');
        }

        // Build tree data from existing sync records.
        $syncRecords = (new Query())
            ->select(['element_id', 'locked'])
            ->from('{{%contentiq_entry_syncs}}')
            ->all();

        $hasSyncRecords = count($syncRecords) > 0;
        $syncGroups     = [];

        if ($hasSyncRecords) {
            $elementIds = array_column($syncRecords, 'element_id');
            $lockedMap  = array_column($syncRecords, 'locked', 'element_id');

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
        $globalsLocked = $globalsRow === null ? true : (bool)$globalsRow['locked'];

        $globalsOfficeCount = (int)(new Query())
            ->from('{{%contentiq_office_syncs}}')
            ->count();

        return $this->renderTemplate('contentiq-importer/_cp/sync', [
            'contentiqUrl'    => App::parseEnv($settings->contentiqUrl),
            'projectSlug'    => $inferredSlug,
            'hasSyncRecords' => $hasSyncRecords,
            'syncGroups'     => $syncGroups,
            'globalsLocked'     => $globalsLocked,
            'globalsOfficeCount' => $globalsOfficeCount,
            'globalsSetNames'    => ['companyInfo', 'globalContent', 'siteConfig'],
        ]);
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

        $settings = ContentIQImporter::$plugin->getSettings();

        if ($settings->contentiqUrl === '' || $settings->apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error'   => 'ContentiQ API is not configured.',
            ]);
        }

        // Apply lock/unlock state from the pre-sync tree view.
        // The browser sends the list of entry IDs the user has unlocked.
        $request   = Craft::$app->getRequest();
        $unlockIds = Json::decodeIfJson($request->getBodyParam('unlockIds', '[]'));

        if (is_array($unlockIds) && !empty($unlockIds)) {
            $db = Craft::$app->getDb();

            // Lock everything first, then unlock only the selected entries.
            $db->createCommand()
                ->update('{{%contentiq_entry_syncs}}', ['locked' => true])
                ->execute();

            $db->createCommand()
                ->update(
                    '{{%contentiq_entry_syncs}}',
                    ['locked' => false],
                    ['element_id' => $unlockIds],
                )
                ->execute();
        }

        // Persist the globals consent for this run (checkbox = unlock). The
        // SyncJob relocks after a successful globals import.
        $unlockGlobals = (bool)$request->getBodyParam('unlockGlobals', false);
        $this->_setGlobalsLock(!$unlockGlobals);

        // Create a pending run record so the frontend has a run ID to poll.
        $runId = $this->_saveRun(
            filename:   'sync',
            type:       'sync',
            pageCount:  0,
            imageCount: 0,
            status:     'pending',
            result:     [],
        );

        // Push the queue job.
        Craft::$app->getQueue()->push(new SyncJob([
            'runId' => $runId,
        ]));

        return $this->asJson([
            'success' => true,
            'runId'   => $runId,
        ]);
    }

    /**
     * Polls the status of a sync run.
     *
     * Returns JSON with the current status — the frontend polls until
     * status is no longer 'pending'.
     *
     * @return Response
     */
    public function actionSyncStatus(): Response
    {
        $this->requireAcceptsJson();

        $runId = Craft::$app->getRequest()->getRequiredQueryParam('runId');

        $status = (new Query())
            ->select(['status'])
            ->from('{{%contentiq_import_runs}}')
            ->where(['id' => $runId])
            ->scalar();

        return $this->asJson([
            'status' => $status ?: 'unknown',
        ]);
    }

    /**
     * Sync result screen — hierarchical report of a completed sync.
     *
     * @param int $runId
     * @return Response
     */
    public function actionSyncResult(int $runId): Response
    {
        $run = (new Query())
            ->from('{{%contentiq_import_runs}}')
            ->where(['id' => $runId])
            ->one();

        if ($run === null) {
            throw new \yii\web\NotFoundHttpException('Sync run not found.');
        }

        $run['result'] = Json::decodeIfJson($run['result'] ?? '[]');

        return $this->renderTemplate('contentiq-importer/_cp/sync-result', [
            'run' => $run,
        ]);
    }

    /**
     * Syncs a single entry from the ContentIQ API.
     *
     * Called via AJAX from the ContentIQ sidebar widget on the entry edit screen.
     * Fetches the single-page export for the entry's slug, runs it through
     * ImportService, and upserts a row in contentiq_entry_syncs on success.
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

        $request   = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $slug      = trim((string)$request->getRequiredBodyParam('slug'));

        if (!$elementId || $slug === '') {
            return $this->asJson(['success' => false, 'error' => 'elementId and slug are required.']);
        }

        // Look up entry title for user-facing messages.
        $entryTitle = Entry::find()->id($elementId)->status(null)->select(['title'])->scalar() ?: $slug;

        $settings = ContentIQImporter::$plugin->getSettings();

        if ($settings->contentiqUrl === '' || $settings->apiKey === '') {
            return $this->asJson([
                'success' => false,
                'error'   => 'ContentiQ API is not configured. Set URL and API key in plugin settings.',
            ]);
        }

        // Map Craft slug to ContentIQ slug if configured.
        $config       = Craft::$app->config->getConfigFromFile('contentiq');
        $slugMap      = $config['slugMap'] ?? [];
        $contentiqSlug = $slugMap[$slug] ?? $slug;

        $url      = rtrim(App::parseEnv($settings->contentiqUrl), '/');
        $endpoint = "{$url}/api/v1/pages/{$contentiqSlug}/export";

        try {
            $response = Craft::createGuzzleClient()->request('GET', $endpoint, [
                RequestOptions::HEADERS => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . App::parseEnv($settings->apiKey),
                ],
                RequestOptions::TIMEOUT         => 30,
                RequestOptions::CONNECT_TIMEOUT => 10,
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $this->asJson(['success' => false, 'error' => 'ContentiQ returned invalid JSON.']);
            }
        } catch (GuzzleException $e) {
            $status = method_exists($e, 'getResponse') && $e->getResponse() !== null
                ? $e->getResponse()->getStatusCode()
                : 0;

            $message = $status === 404
                ? "'{$entryTitle}' is not ready for export in ContentiQ."
                : 'API request failed: ' . $e->getMessage();

            return $this->asJson(['success' => false, 'error' => $message]);
        }

        // Run the import pipeline (no dry-run).
        $result = ContentIQImporter::$plugin->imports->importPage($data, dryRun: false);

        if (!$result['success']) {
            return $this->asJson([
                'success' => false,
                'error'   => $result['error'] ?? 'Import failed.',
            ]);
        }

        // Upsert the sync timestamp and notes.
        $now   = Db::prepareDateForDb(new \DateTime());
        $notes = $result['blockNotes'] ?? '';
        $db    = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%contentiq_entry_syncs}}')
            ->where(['element_id' => $elementId])
            ->exists();

        $syncData = ['synced_at' => $now, 'notes' => $notes, 'locked' => true];

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_entry_syncs}}', $syncData, ['element_id' => $elementId])
                ->execute();
        } else {
            $db->createCommand()
                ->insert('{{%contentiq_entry_syncs}}', array_merge(['element_id' => $elementId], $syncData))
                ->execute();
        }

        $syncedAt = Craft::$app->getFormatter()->asDatetime($now, 'short');

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
                    'synced_at'  => (new \DateTime())->format('Y-m-d H:i:s'),
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

        $elementId = (int)Craft::$app->getRequest()->getRequiredBodyParam('elementId');

        Craft::$app->getDb()->createCommand()
            ->update('{{%contentiq_entry_syncs}}', ['notes' => ''], ['element_id' => $elementId])
            ->execute();

        return $this->asJson(['success' => true]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the cascade data for the Mappings screen: every section with its
     * entry types, and each entry type's CKEditor and PlainText field handles.
     *
     * Embedded as JSON so the vanilla-JS dropdowns can cascade section → entry
     * type → content field (CKEditor) / heading field (PlainText).
     *
     * @return array<int, array{handle: string, name: string, entryTypes: array<int, array{handle: string, name: string, ckeditorFields: array<int, array{handle: string, name: string}>, plainTextFields: array<int, array{handle: string, name: string}>}>}>
     */
    private function _buildSectionsData(): array
    {
        $data = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $entryTypes = [];

            foreach ($section->getEntryTypes() as $entryType) {
                $ckeditorFields  = [];
                $plainTextFields = [];

                foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
                    // CKEditor is an optional dependency — reference it by its
                    // fully-qualified name so this works without the plugin.
                    if ($field instanceof \craft\ckeditor\Field) {
                        $ckeditorFields[] = ['handle' => $field->handle, 'name' => $field->name];
                    } elseif ($field instanceof PlainText) {
                        $plainTextFields[] = ['handle' => $field->handle, 'name' => $field->name];
                    }
                }

                $entryTypes[] = [
                    'handle'          => $entryType->handle,
                    'name'            => $entryType->name,
                    'ckeditorFields'  => $ckeditorFields,
                    'plainTextFields' => $plainTextFields,
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
     * Sets the single globals-sync lock row, creating it if absent.
     *
     * @param bool $locked
     * @return void
     */
    private function _setGlobalsLock(bool $locked): void
    {
        $db     = Craft::$app->getDb();
        $exists = (new Query())->from('{{%contentiq_globals_sync}}')->exists();

        if ($exists) {
            $db->createCommand()
                ->update('{{%contentiq_globals_sync}}', ['locked' => $locked])
                ->execute();
            return;
        }

        $db->createCommand()
            ->insert('{{%contentiq_globals_sync}}', ['locked' => $locked])
            ->execute();
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
            'dateCreated' => (new \DateTime())->format('Y-m-d H:i:s'),
            'dateUpdated' => (new \DateTime())->format('Y-m-d H:i:s'),
            'uid'         => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        return (int)$db->getLastInsertID();
    }
}
