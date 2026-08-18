<?php

namespace matrixcreate\contentiqimporter\services;

use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\Db;
use craft\models\Volume;
use craft\models\VolumeFolder;
use GuzzleHttp\RequestOptions;
use matrixcreate\contentiqimporter\helpers\UrlSafety;
use Throwable;
use yii\base\Component;
use yii\base\Exception;

/**
 * Handles downloading remote images and importing them as Craft assets.
 *
 * Idempotent: if the image's key has already been mapped to a live Craft
 * asset (contentiq_asset_syncs), that asset is reused — the key is the
 * upstream asset's stable storage path and is globally unique, so this is
 * collision-proof across pages/projects that happen to share a filename.
 * When the key is unmapped, an asset with the same filename in the target
 * folder is reused as a fallback (and the key mapping is recorded for next
 * time). Otherwise the file is downloaded and a new asset created. Running
 * the import twice does NOT duplicate assets.
 *
 * Failed downloads are non-fatal: the method returns null and logs a warning.
 * Callers should treat null as "leave field empty" and continue.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class ImageImportService extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * Cached volume instance, resolved once per import run.
     *
     * @var Volume|null
     */
    private ?Volume $_volume = null;

    /**
     * Cached target folder instance, resolved once per import run.
     *
     * @var VolumeFolder|null
     */
    private ?VolumeFolder $_folder = null;

    // Public Methods
    // =========================================================================

    /**
     * Resolves the configured volume and folder, caching both for the run.
     *
     * Must be called once before any importFromUrl() calls. Throws if the
     * volume handle is not found — this is a fatal configuration error — UNLESS
     * $dryRun is true, in which case a missing volume or folder is left
     * unresolved (no throw, no DB writes) and importFromField() reports each
     * image as a would-be new import.
     *
     * @param string $volumeHandle  e.g. 'images'
     * @param string $folderPath    e.g. 'contentiq'
     * @param bool   $dryRun        Read-only resolve — never creates the folder record.
     * @return void
     * @throws Exception if the volume handle cannot be resolved (non-dry-run only).
     */
    public function prepare(string $volumeHandle, string $folderPath, bool $dryRun = false): void
    {
        $volume = Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);

        if ($volume === null) {
            if ($dryRun) {
                // Dry-run must not throw on a misconfigured volume — leave unresolved.
                $this->_volume = null;
                $this->_folder = null;

                return;
            }

            throw new Exception("Asset volume '{$volumeHandle}' not found. Check the 'assetVolume' key in config/contentiq.php.");
        }

        if ($dryRun) {
            // Read-only resolve: never create the folder record on a dry run. A
            // not-yet-existing folder resolves to null (nothing to reuse).
            $this->_volume = $volume;
            $this->_folder = Craft::$app->getAssets()->findFolder([
                'volumeId' => $volume->id,
                'path'     => $folderPath === '' ? '' : rtrim($folderPath, '/') . '/',
            ]);

            return;
        }

        $folder = Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(
            $folderPath,
            $volume,
            true, // justRecord — volume handles physical dir creation on save
        );

        $this->_volume = $volume;
        $this->_folder = $folder;
    }

    /**
     * Imports an image from a ContentIQ image field value.
     *
     * Accepts the raw image object from the JSON:
     *   { "key": "path/to/file.jpg", "url": "https://...", "alt": null }
     *
     * Returns the Craft asset ID on success, null on failure or if the image
     * field is empty (no url).
     *
     * @param array|null $imageField  Raw image object from ContentIQ JSON.
     * @param bool       $dryRun     If true, resolves what would happen without downloading.
     * @return array{id: int|null, filename: string, reused: bool}|null
     *   Returns null if the image field is empty or unusable.
     */
    public function importFromField(?array $imageField, bool $dryRun = false): ?array
    {
        if (empty($imageField['url'])) {
            return null;
        }

        $url      = $imageField['url'];
        $imageKey = (string)($imageField['key'] ?? '');
        $rawFilename = $this->_filenameFromKey($imageKey) ?: $this->_filenameFromUrl($url);
        // Sanitize the same way Craft does on save — spaces become hyphens, etc.
        $filename = Assets::prepareAssetName($rawFilename);

        if ($filename === '') {
            Craft::warning("Could not determine filename from image field: " . json_encode($imageField), __METHOD__);

            return null;
        }

        // Volume/folder may be unresolved on a dry run (missing volume, or a
        // folder that does not exist yet). Nothing can be reused — report a
        // would-be new import; a non-dry-run should never reach this (prepare
        // throws), but fail safe.
        if ($this->_volume === null || $this->_folder === null) {
            return $dryRun ? ['id' => null, 'filename' => $filename, 'reused' => false] : null;
        }

        // STEP A — key-first identity. The image key is the upstream asset's
        // stable storage path ("{project_id}/{page_id}/{filename}") and is
        // globally unique, unlike the bare filename used below — two images
        // from different pages/projects can share a filename but never a
        // key. A key already mapped to a still-live asset wins outright and
        // the download is skipped entirely.
        if ($imageKey !== '') {
            $mappedElementId = (new Query())
                ->select(['element_id'])
                ->from('{{%contentiq_asset_syncs}}')
                ->where(['image_key' => $imageKey])
                ->scalar();

            // Query::scalar() returns false (not null) when no row matches.
            if ($mappedElementId !== false) {
                $mapped = Asset::find()->id((int)$mappedElementId)->one();

                if ($mapped !== null) {
                    return ['id' => $mapped->id, 'filename' => $filename, 'reused' => true];
                }

                // Stale mapping — the mapped asset was hard-deleted or
                // trashed (default Asset::find() excludes trashed, so null
                // here means exactly that). Clear it so a fresh mapping can
                // be recorded below; a dry run must not write, so it simply
                // falls through to the filename fallback for this preview.
                if (!$dryRun) {
                    Craft::$app->getDb()->createCommand()
                        ->delete('{{%contentiq_asset_syncs}}', ['image_key' => $imageKey])
                        ->execute();
                }
            }
        }

        // STEP B — filename fallback (unchanged idempotency behaviour).
        // Reuse if an asset with this filename already exists in the target
        // folder. This is how a key gets adopted on the first post-upgrade
        // sync: an asset created before contentiq_asset_syncs existed has no
        // key mapping yet, so it's found here and the mapping recorded below.
        $existing = Asset::find()
            ->volumeId($this->_volume->id)
            ->folderId($this->_folder->id)
            ->filename($filename)
            ->one();

        if ($existing !== null) {
            if (!$dryRun && $imageKey !== '') {
                $this->_upsertAssetMap($imageKey, $existing->id);
            }

            return ['id' => $existing->id, 'filename' => $filename, 'reused' => true];
        }

        if ($dryRun) {
            return ['id' => null, 'filename' => $filename, 'reused' => false];
        }

        // If the file exists on the volume but not in the DB (orphaned from a
        // previous partial import or manual DB cleanup), remove it so Craft's
        // "file already exists" validation does not block the save.
        $this->_deleteOrphanedFile($filename);

        $alt = isset($imageField['alt']) && $imageField['alt'] !== '' ? (string)$imageField['alt'] : null;

        $result = $this->_download($url, $filename, $alt);

        if ($result !== null && $imageKey !== '') {
            $this->_upsertAssetMap($imageKey, $result['id']);
        }

        return $result;
    }

    /**
     * Resets cached volume and folder. Call between import runs if needed.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->_volume = null;
        $this->_folder = null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Deletes a file from the volume if it exists on the filesystem without a
     * corresponding asset DB record — LIVE OR TRASHED — for that filename
     * (a genuine orphan).
     *
     * This can happen when asset DB records are hard-deleted manually (e.g.
     * during testing) but the volume files are not removed. Craft's "file
     * already exists" validation would otherwise block creating a new asset
     * with the same name.
     *
     * Trashed assets must be spared: Craft soft-deletes into a restorable
     * trash, and the asset's physical file is what a restore brings back. If
     * this only checked live (non-trashed) assets — Craft's default
     * Asset::find() behaviour — a soft-deleted asset would look like "no DB
     * record" here and its file would be permanently wiped, breaking the
     * restore. Checking trashed rows too also spares an out-of-band editor
     * upload that happens to share this filename but isn't findable via the
     * volumeId/folderId/filename lookup for some other reason (e.g. it was
     * itself just trashed) from being deleted underneath the editor.
     *
     * @param string $filename
     * @return void
     */
    private function _deleteOrphanedFile(string $filename): void
    {
        try {
            $hasAssetRecord = Asset::find()
                ->volumeId($this->_volume->id)
                ->folderId($this->_folder->id)
                ->filename($filename)
                ->trashed(null) // null = match both live and trashed rows.
                ->exists();

            if ($hasAssetRecord) {
                // Not an orphan — a DB record (live or trashed) owns this
                // file. Leave it alone.
                return;
            }

            $fs         = $this->_volume->getFs();
            $folderPath = $this->_folder->path ? rtrim($this->_folder->path, '/') . '/' : '';
            $volumePath = $folderPath . $filename;

            if ($fs->fileExists($volumePath)) {
                $fs->deleteFile($volumePath);
                Craft::info("Deleted orphaned file from volume: {$volumePath}", __METHOD__);
            }
        } catch (Throwable $e) {
            Craft::warning("Could not check/delete orphaned file '{$filename}': " . $e->getMessage(), __METHOD__);
        }
    }

    /**
     * Upserts a contentiq_asset_syncs row (image key → element id).
     *
     * Called after every resolution path that ends in a resolved asset id —
     * a filename-fallback reuse (STEP B) or a freshly downloaded asset — so
     * the key becomes resolvable directly next time (STEP A). Not called for
     * a STEP A key-based reuse, since that mapping is already correct.
     *
     * @param string $imageKey
     * @param int    $elementId
     * @return void
     */
    private function _upsertAssetMap(string $imageKey, int $elementId): void
    {
        $now = Db::prepareDateForDb(new \DateTime());
        $db  = Craft::$app->getDb();

        $exists = (new Query())
            ->from('{{%contentiq_asset_syncs}}')
            ->where(['image_key' => $imageKey])
            ->exists();

        if ($exists) {
            $db->createCommand()->update(
                '{{%contentiq_asset_syncs}}',
                ['element_id' => $elementId, 'dateUpdated' => $now],
                ['image_key' => $imageKey],
            )->execute();
            return;
        }

        $db->createCommand()->insert('{{%contentiq_asset_syncs}}', [
            'image_key'   => $imageKey,
            'element_id'  => $elementId,
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ])->execute();
    }

    /**
     * Downloads a remote URL to a temp file and imports it as a Craft asset.
     *
     * Returns the result array on success, null on any failure.
     *
     * The alt text is only applied to newly created assets — existing assets
     * (reused by filename) are never touched, so editor-set alt text survives.
     *
     * @param string $url
     * @param string $filename
     * @param string|null $alt Alt text from the wire image object (new assets only).
     * @return array{id: int, filename: string, reused: false}|null
     */
    private function _download(string $url, string $filename, ?string $alt = null): ?array
    {
        // SSRF guard — $url comes from attacker-controllable uploaded/synced
        // JSON. Refuse anything that isn't a public http(s) host before making
        // any request (cloud metadata endpoints, internal services, etc.).
        if (!UrlSafety::isPublicHttpUrl($url)) {
            Craft::warning("Refused to download image — not a public http(s) URL: $url", __METHOD__);

            return null;
        }

        $tempPath = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid('contentiq_') . '_' . $filename;

        try {
            $response = Craft::createGuzzleClient()->request('GET', $url, [
                RequestOptions::SINK            => $tempPath,
                RequestOptions::TIMEOUT         => 30,
                // A public URL must not be able to 302 its way to an internal
                // one — never follow redirects on this request.
                RequestOptions::ALLOW_REDIRECTS => false,
            ]);

            // With redirects disabled, a 3xx is not an image — reject it
            // explicitly instead of importing whatever small body it sent.
            if ($response->getStatusCode() >= 300) {
                Craft::warning("Image download got HTTP {$response->getStatusCode()} (redirects are disabled): $url", __METHOD__);

                return null;
            }

            if (!is_file($tempPath) || filesize($tempPath) === 0) {
                Craft::warning("Downloaded file is empty or missing: $url", __METHOD__);

                return null;
            }

            // Sane upper bound so a misbehaving/malicious endpoint streaming an
            // unbounded body doesn't get imported as an asset.
            $maxBytes = 25 * 1024 * 1024;
            if (filesize($tempPath) > $maxBytes) {
                Craft::warning("Downloaded file exceeds the {$maxBytes}-byte limit: $url", __METHOD__);

                return null;
            }

            $asset = new Asset();
            $asset->setScenario(Asset::SCENARIO_CREATE);
            $asset->tempFilePath = $tempPath;
            // newLocation is what SCENARIO_CREATE validation requires — format: {folder:X}filename
            $asset->newLocation  = "{folder:{$this->_folder->id}}{$filename}";
            $asset->title        = pathinfo($filename, PATHINFO_FILENAME);

            if ($alt !== null) {
                $asset->alt = $alt;
            }

            $saved = Craft::$app->getElements()->saveElement($asset);

            if (!$saved) {
                $errors = implode(', ', $asset->getFirstErrors());
                Craft::warning("Failed to save asset '{$filename}': {$errors}", __METHOD__);

                return null;
            }

            return ['id' => $asset->id, 'filename' => $filename, 'reused' => false];
        } catch (Throwable $e) {
            Craft::warning("Exception importing image '{$filename}': " . $e->getMessage(), __METHOD__);

            return null;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * Extracts just the base filename from a ContentIQ S3 key.
     *
     * The key format is 'project/path/to/filename.jpg'. Returns the last segment.
     *
     * @param string $key
     * @return string
     */
    private function _filenameFromKey(string $key): string
    {
        if ($key === '') {
            return '';
        }

        // Strip any thumbnail path segment (e.g. '.thumbs/') used by ContentIQ.
        $basename = basename($key);

        return $basename !== '.' ? $basename : '';
    }

    /**
     * Extracts a filename from a URL as a fallback.
     *
     * Strips query strings before extracting the basename.
     *
     * @param string $url
     * @return string
     */
    private function _filenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if ($path === null || $path === false) {
            return '';
        }

        return basename($path);
    }
}
